<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class PaymentController extends Controller
{
    public static function generateReceiptNo()
    {
        $lastPayment = Payment::whereRaw("receipt_no REGEXP '^[0-9]{8}$'")
            ->orderBy('id', 'desc')
            ->first();

        if ($lastPayment && is_numeric($lastPayment->receipt_no) && strlen($lastPayment->receipt_no) === 8) {
            $next = (int) $lastPayment->receipt_no + 1;
        } else {
            $next = 10000001;
        }

        while (Payment::where('receipt_no', (string) $next)->exists()) {
            $next++;
        }

        return (string) $next;
    }

    public static function syncCustomerBillStatuses($customerId)
    {
        $customer = Customer::find($customerId);
        if (!$customer) return;

        $bills = Bill::where('customer_id', $customerId)
            ->orderBy('bill_month', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $totalPayments = (float) Payment::where('customer_id', $customerId)->sum('amount_paid');
        $totalGrossBills = (float) $bills->sum('amount');

        // Always sync customer advance credit balance accurately based on gross bills
        $newAdvanceBalance = max(0, $totalPayments - $totalGrossBills);
        $customer->update([
            'advance_balance' => $newAdvanceBalance,
            'advance' => $newAdvanceBalance,
        ]);

        if ($bills->isEmpty()) return;

        $rem = $totalPayments;

        foreach ($bills as $bill) {
            $grossAmount = (float) $bill->amount;
            $dues = (float) ($bill->previous_dues ?? 0);
            $advanceApplied = (float) ($bill->advance ?? 0);
            $adj = (float) ($bill->adjustment ?? 0);
            $adjEffect = $bill->adjustment_type === 'Debit' ? $adj : ($bill->adjustment_type === 'Credit' ? -$adj : 0);

            $billGross = ($grossAmount + $dues) + $adjEffect;
            $netRequired = max(0, $billGross - $advanceApplied);

            if ($netRequired <= 0) {
                $bill->update(['status' => 'paid']);
                $rem = max(0, $rem - $billGross);
                continue;
            }

            $paidForBill = min($rem, $netRequired);
            $rem = max(0, $rem - $paidForBill);

            if (($paidForBill + $advanceApplied) >= $billGross) {
                $bill->update(['status' => 'paid']);
            } elseif (($paidForBill + $advanceApplied) > 0) {
                $bill->update(['status' => 'partial']);
            } else {
                $bill->update(['status' => 'unpaid']);
            }
        }
    }

    public function collectorCustomers(Request $request)
    {
        $query = Customer::with(['area']);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->get();

        foreach ($customers as $customer) {
            self::syncCustomerBillStatuses($customer->id);
        }

        $customerIds = $customers->pluck('id');
        $result = Customer::with(['area', 'bills' => function ($q) {
            $q->whereIn('status', ['unpaid', 'partial'])->orderBy('bill_month', 'asc');
        }])->whereIn('id', $customerIds)->get();

        foreach ($result as $customer) {
            $allBills = Bill::where('customer_id', $customer->id)->orderBy('bill_month', 'asc')->orderBy('id', 'asc')->get();
            $customer->total_bills_count = $allBills->count();
            $totalPaidPool = (float) Payment::where('customer_id', $customer->id)->sum('amount_paid');
            
            $billDuesMap = [];
            $remPaid = $totalPaidPool;
            foreach ($allBills as $b) {
                $bAmt = (float) $b->amount;
                $paidForThis = min($remPaid, $bAmt);
                $remPaid -= $paidForThis;
                $dueForThis = max(0, $bAmt - $paidForThis);
                $billDuesMap[$b->id] = $dueForThis;
            }

            foreach ($customer->bills as $b) {
                $b->calculated_due = $billDuesMap[$b->id] ?? (float) $b->amount;
            }
        }

        return response()->json($result);
    }

    public function store(Request $request)
    {
        $request->validate([
            'bill_id'        => 'nullable|exists:bills,id',
            'bill_ids'       => 'nullable|array',
            'bill_ids.*'     => 'exists:bills,id',
            'amount_paid'    => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bkash,nagad,bank',
            'payment_date'   => 'nullable|date',
        ]);

        return DB::transaction(function () use ($request) {
            $billIds = $request->bill_ids ?? [];
            if (empty($billIds) && $request->bill_id) {
                $billIds = [$request->bill_id];
            }

            $customerId = $request->customer_id;
            if (!$customerId && !empty($billIds)) {
                $firstBill = Bill::find($billIds[0]);
                $customerId = $firstBill ? $firstBill->customer_id : null;
            }

            if (!$customerId) {
                return response()->json(['message' => 'Customer ID is required.'], 422);
            }

            $customer = Customer::findOrFail($customerId);

            $hasGeneratedBills = Bill::where('customer_id', $customerId)->exists();
            if (!$hasGeneratedBills) {
                return response()->json([
                    'message' => 'Payment collection is not allowed because no bill has been generated yet for this customer.'
                ], 422);
            }

            $paymentDate = $request->filled('payment_date') ? Carbon::parse($request->payment_date) : now();

            // Generate single 8-digit Unique Receipt Number (e.g. 10000001)
            $receiptNo = self::generateReceiptNo();

            $amountPaid = (float) $request->amount_paid;

            // Calculate customer total gross bills (net amount + advance) and previous total payments BEFORE this payment
            $totalGrossBills = (float) Bill::where('customer_id', $customerId)->sum(DB::raw('amount + COALESCE(advance, 0)'));
            $previousPayments = (float) Payment::where('customer_id', $customerId)->sum('amount_paid');
            $netDuesBeforePayment = max(0, $totalGrossBills - $previousPayments);

            // Advance credit is ONLY added if amount paid exceeds ALL unpaid dues of the customer across all generated bills
            $advanceAdded = max(0, $amountPaid - $netDuesBeforePayment);

            // Find the LAST bill ID of this customer at the time of payment
            $lastBill = Bill::where('customer_id', $customerId)
                ->orderBy('bill_month', 'desc')
                ->orderBy('id', 'desc')
                ->first();

            $lastBillId = $lastBill ? $lastBill->id : null;

            // Create ONE unified payment record posted to the LAST bill ID
            $payment = Payment::create([
                'bill_id'        => $lastBillId,
                'customer_id'    => $customerId,
                'collected_by'   => auth()->id() ?? 1,
                'amount_paid'    => $amountPaid,
                'payment_method' => $request->payment_method ?? 'cash',
                'payment_date'   => $paymentDate,
                'receipt_no'     => $receiptNo,
                'notes'          => $request->notes,
            ]);

            // Update customer advance_balance accurately
            $newAdvanceBalance = max(0, ($previousPayments + $amountPaid) - $totalGrossBills);
            $customer->update(['advance_balance' => $newAdvanceBalance]);

            // Sync customer bill statuses chronologically
            self::syncCustomerBillStatuses($customerId);

            // Calculate collected bill months for receipt display
            $allBills = Bill::where('customer_id', $customerId)->orderBy('bill_month', 'asc')->orderBy('id', 'asc')->get();
            $remPaidBefore = $previousPayments;
            $remPaidAfter = $previousPayments + $amountPaid;
            $collectedMonths = [];

            foreach ($allBills as $b) {
                $bAmt = (float) $b->amount;
                $paidBefore = min($remPaidBefore, $bAmt);
                $remPaidBefore = max(0, $remPaidBefore - $paidBefore);

                $paidAfter = min($remPaidAfter, $bAmt);
                $remPaidAfter = max(0, $remPaidAfter - $paidAfter);

                if (($paidAfter - $paidBefore) > 0) {
                    $collectedMonths[] = $b->bill_month;
                }
            }

            $payment->load(['customer.area', 'collector', 'bill']);
            $payment->receipt_no = $receiptNo;
            $payment->total_amount_paid = $amountPaid;
            $payment->collected_months = !empty($collectedMonths) ? implode(', ', $collectedMonths) : 'Advance Credit Payment';
            $payment->advance_added = $advanceAdded;

            return response()->json($payment, 201);
        });
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $query = Payment::with(['customer.area', 'bill', 'collector']);

        if ($user->hasRole('collector')) {
            $query->where('collected_by', $user->id);
        } elseif ($request->filled('collector_id')) {
            $query->where('collected_by', $request->collector_id);
        }

        if ($request->filled('date')) {
            $query->whereDate('payment_date', $request->date);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('receipt_no', 'like', "%{$search}%")
                  ->orWhereHas('customer', function ($cq) use ($search) {
                      $cq->where('customer_code', 'like', "%{$search}%")
                        ->orWhere('name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        $payments = $query->orderBy('payment_date', 'desc')->get();

        return response()->json($payments);
    }

    public function downloadSampleExcel()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bulk Payment Import');

        $headers = [
            'Customer Code or Phone *',
            'Amount Paid (Tk) *',
            'Payment Method (cash/bkash/nagad/bank) *',
            'Payment Date (YYYY-MM-DD) *',
            'Notes / Remarks'
        ];

        $sheet->fromArray($headers, null, 'A1');

        $headerRange = 'A1:E1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF059669');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Sample Rows
        $sampleData = [
            ['CCL00001', '800', 'cash', '2026-08-01', 'Cash collected by Field Collector'],
            ['01899887766', '1600', 'bkash', '2026-08-01', 'bKash TrxID #9X8A12'],
        ];

        $sheet->fromArray($sampleData, null, 'A2');

        foreach (range('A', 'E') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'Sample_Bulk_Payment_Import_Template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function importExcel(Request $request)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls,csv|max:10240',
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) <= 1) {
            return response()->json(['message' => 'The uploaded file is empty.'], 422);
        }

        $importedCount = 0;
        $skippedCount = 0;

        DB::transaction(function () use ($rows, &$importedCount, &$skippedCount) {
            for ($i = 2; $i <= count($rows); $i++) {
                $row = $rows[$i];

                $codeOrPhone = trim($row['A'] ?? '');
                $amountPaid  = (float) ($row['B'] ?? 0);
                $method      = strtolower(trim($row['C'] ?? 'cash'));
                $payDateRaw  = trim($row['D'] ?? '');
                $notes       = trim($row['E'] ?? '');

                if (empty($codeOrPhone) || $amountPaid <= 0) {
                    $skippedCount++;
                    continue;
                }

                $customer = Customer::where('customer_code', $codeOrPhone)
                    ->orWhere('phone', $codeOrPhone)
                    ->first();

                if (!$customer) {
                    $skippedCount++;
                    continue;
                }

                $payDate = !empty($payDateRaw) ? Carbon::parse($payDateRaw) : now();
                $method = in_array($method, ['cash', 'bkash', 'nagad', 'bank']) ? $method : 'cash';

                $previousPayments = (float) Payment::where('customer_id', $customer->id)->sum('amount_paid');
                $totalGrossBills = (float) Bill::where('customer_id', $customer->id)->sum(DB::raw('amount + COALESCE(advance, 0)'));

                $receiptNo = self::generateReceiptNo();

                $lastBill = Bill::where('customer_id', $customer->id)
                    ->orderBy('bill_month', 'desc')
                    ->orderBy('id', 'desc')
                    ->first();
                $lastBillId = $lastBill ? $lastBill->id : null;

                Payment::create([
                    'bill_id'        => $lastBillId,
                    'customer_id'    => $customer->id,
                    'collected_by'   => auth()->id() ?? 1,
                    'amount_paid'    => $amountPaid,
                    'payment_method' => $method,
                    'payment_date'   => $payDate,
                    'receipt_no'     => $receiptNo,
                    'notes'          => $notes,
                ]);

                $newAdvanceBalance = max(0, ($previousPayments + $amountPaid) - $totalGrossBills);
                $customer->update(['advance_balance' => $newAdvanceBalance]);

                self::syncCustomerBillStatuses($customer->id);

                $importedCount++;
            }
        });

        return response()->json([
            'message'        => "Bulk Payment Import Completed Successfully!",
            'imported_count' => $importedCount,
            'skipped_count'  => $skippedCount,
        ]);
    }

    public function exportCollectionSummaryExcel(Request $request)
    {
        $user = $request->user();
        $query = Payment::with(['customer.area', 'bill', 'collector']);

        if ($user->hasRole('collector')) {
            $query->where('collected_by', $user->id);
        } elseif ($request->filled('collector_id')) {
            $query->where('collected_by', $request->collector_id);
        }

        if ($request->filled('start_date')) {
            $query->whereDate('payment_date', '>=', $request->start_date);
        }

        if ($request->filled('end_date')) {
            $query->whereDate('payment_date', '<=', $request->end_date);
        }

        if ($request->filled('area_id')) {
            $query->whereHas('customer', function ($q) use ($request) {
                $q->where('area_id', $request->area_id);
            });
        }

        if ($request->filled('payment_method')) {
            $query->where('payment_method', $request->payment_method);
        }

        $payments = $query->orderBy('payment_date', 'desc')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Collection Summary');

        $totalCollected = (float) $payments->sum('amount_paid');
        $cashTotal = (float) $payments->where('payment_method', 'cash')->sum('amount_paid');
        $bkashTotal = (float) $payments->where('payment_method', 'bkash')->sum('amount_paid');
        $nagadTotal = (float) $payments->where('payment_method', 'nagad')->sum('amount_paid');
        $bankTotal = (float) $payments->where('payment_method', 'bank')->sum('amount_paid');

        $sheet->setCellValue('A1', 'CABLE TV COLLECTION SUMMARY REPORT');
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF059669'));

        $sheet->setCellValue('A2', 'Report Period: ' . ($request->start_date ?? 'All Time') . ' to ' . ($request->end_date ?? date('Y-m-d')) . ' | Total Records: ' . $payments->count());
        $sheet->mergeCells('A2:J2');
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(10);

        $sheet->setCellValue('A4', 'TOTAL REVENUE');
        $sheet->setCellValue('B4', $totalCollected);
        $sheet->setCellValue('D4', 'CASH: Tk ' . number_format($cashTotal, 2));
        $sheet->setCellValue('F4', 'BKASH: Tk ' . number_format($bkashTotal, 2));
        $sheet->setCellValue('H4', 'NAGAD: Tk ' . number_format($nagadTotal, 2));
        $sheet->setCellValue('J4', 'BANK: Tk ' . number_format($bankTotal, 2));

        $sheet->getStyle('A4:J4')->getFont()->setBold(true);
        $sheet->getStyle('B4')->getNumberFormat()->setFormatCode('#,##0.00');

        $headers = [
            'Receipt No',
            'Payment Date & Time',
            'Customer Code',
            'Subscriber Name',
            'Phone Number',
            'Area Zone',
            'Amount Paid (Tk)',
            'Payment Method',
            'Collected By',
            'Notes / Remarks'
        ];

        $sheet->fromArray($headers, null, 'A6');

        $headerRange = 'A6:J6';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF059669');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(6)->setRowHeight(26);

        $rowNum = 7;
        foreach ($payments as $p) {
            $c = $p->customer;
            $data = [
                $p->receipt_no,
                Carbon::parse($p->payment_date)->format('Y-m-d H:i'),
                $c ? $c->customer_code : '-',
                $c ? $c->name : '-',
                $c ? $c->phone : '-',
                ($c && $c->area) ? $c->area->name : '-',
                (float) $p->amount_paid,
                strtoupper($p->payment_method),
                $p->collector ? $p->collector->name : 'System Admin',
                $p->notes ?? '-',
            ];

            $sheet->fromArray($data, null, 'A' . $rowNum);
            $sheet->getStyle('G' . $rowNum)->getNumberFormat()->setFormatCode('#,##0.00');

            $rowNum++;
        }

        foreach (range('A', 'J') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'Collection_Summary_Report_' . date('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }

    public function update(Request $request, Payment $payment)
    {
        if (!$request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized. Only Super Admin can edit payment collections.'], 403);
        }

        $request->validate([
            'amount_paid'    => 'required|numeric|min:0.01',
            'payment_method' => 'required|in:cash,bkash,nagad,bank',
            'payment_date'   => 'nullable|date',
            'notes'          => 'nullable|string|max:500',
        ]);

        DB::transaction(function () use ($request, $payment) {
            $payment->update([
                'amount_paid'    => $request->amount_paid,
                'payment_method' => $request->payment_method,
                'payment_date'   => $request->payment_date ? Carbon::parse($request->payment_date) : $payment->payment_date,
                'notes'          => $request->notes,
            ]);

            self::syncCustomerBillStatuses($payment->customer_id);
        });

        return response()->json([
            'message' => 'Payment collection updated successfully!',
            'payment' => $payment->fresh(['customer', 'bill', 'collector']),
        ]);
    }

    public function destroy(Request $request, Payment $payment)
    {
        if (!$request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized. Only Super Admin can delete payment collections.'], 403);
        }

        DB::transaction(function () use ($payment) {
            $customerId = $payment->customer_id;
            $payment->delete();

            self::syncCustomerBillStatuses($customerId);
        });

        return response()->json(['message' => 'Payment collection deleted successfully!']);
    }
}
