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
    public function collectorCustomers(Request $request)
    {
        $user = $request->user();
        
        $query = Customer::with(['area', 'bills' => function ($q) {
            $q->with('payments')->whereIn('status', ['unpaid', 'partial'])->orderBy('bill_month', 'asc');
        }]);

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->get();

        return response()->json($customers);
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

            $paymentDate = $request->filled('payment_date') ? Carbon::parse($request->payment_date) : now();

            // Generate single Receipt Number: RCPT-YYYYMMDD-XXXX
            $datePrefix = 'RCPT-' . date('Ymd') . '-';
            $lastPaymentToday = Payment::where('receipt_no', 'like', $datePrefix . '%')->count();
            $receiptNo = $datePrefix . str_pad($lastPaymentToday + 1, 4, '0', STR_PAD_LEFT);

            // Handle Direct Advance Credit Payment if no unpaid bills exist
            if (empty($billIds)) {
                if (!$request->filled('customer_id')) {
                    return response()->json(['message' => 'Please select a customer or bill to collect.'], 422);
                }

                $customer = Customer::findOrFail($request->customer_id);
                $advanceAmount = (float) $request->amount_paid;

                if ($advanceAmount <= 0) {
                    return response()->json(['message' => 'Please enter a valid advance payment amount.'], 422);
                }

                $payment = Payment::create([
                    'bill_id'        => null,
                    'customer_id'    => $customer->id,
                    'collected_by'   => auth()->id() ?? 1,
                    'amount_paid'    => $advanceAmount,
                    'payment_method' => $request->payment_method ?? 'cash',
                    'payment_date'   => $paymentDate,
                    'receipt_no'     => $receiptNo,
                    'notes'          => $request->notes,
                ]);

                $customer->increment('advance_balance', $advanceAmount);

                $payment->load(['customer.area', 'collector']);
                $payment->receipt_no = $receiptNo;
                $payment->total_amount_paid = $advanceAmount;
                $payment->collected_months = 'Advance Credit Payment';
                $payment->advance_added = $advanceAmount;

                return response()->json($payment, 201);
            }

            $bills = Bill::with('customer')->whereIn('id', $billIds)->orderBy('bill_month', 'asc')->get();
            if ($bills->isEmpty()) {
                return response()->json(['message' => 'Selected bills not found.'], 404);
            }

            $customer = $bills->first()->customer;

            $remainingAmount = (float) $request->amount_paid;
            $createdPayments = [];
            $collectedMonths = [];

            foreach ($bills as $index => $bill) {
                if ($remainingAmount <= 0) break;

                $existingPaid = (float) $bill->payments()->sum('amount_paid');
                $dueForThisBill = max(0, (float) $bill->amount - $existingPaid);

                if ($dueForThisBill <= 0) continue;

                $payForThisBill = min($remainingAmount, $dueForThisBill);
                $remainingAmount -= $payForThisBill;

                $itemReceiptNo = count($bills) > 1
                    ? $receiptNo . '-' . ($index + 1)
                    : $receiptNo;

                $payment = Payment::create([
                    'bill_id'        => $bill->id,
                    'customer_id'    => $bill->customer_id,
                    'collected_by'   => auth()->id(),
                    'amount_paid'    => $payForThisBill,
                    'payment_method' => $request->payment_method,
                    'payment_date'   => $paymentDate,
                    'receipt_no'     => $itemReceiptNo,
                    'notes'          => $request->notes,
                ]);

                // Update Bill status
                $newTotalPaid = $existingPaid + $payForThisBill;
                if ($newTotalPaid >= (float) $bill->amount) {
                    $bill->update(['status' => 'paid']);
                } else {
                    $bill->update(['status' => 'partial']);
                }

                $createdPayments[] = $payment;
                $collectedMonths[] = $bill->bill_month;
            }

            // If there's surplus payment beyond all selected bills, add to customer advance_balance!
            $advanceAdded = 0.00;
            if ($remainingAmount > 0) {
                $advanceAdded = $remainingAmount;
                $customer->increment('advance_balance', $remainingAmount);
            }

            $primaryPayment = end($createdPayments);
            if (!$primaryPayment) {
                // In case no bills were due but customer paid advance directly
                $primaryPayment = Payment::create([
                    'bill_id'        => null,
                    'customer_id'    => $customer->id,
                    'collected_by'   => auth()->id(),
                    'amount_paid'    => $request->amount_paid,
                    'payment_method' => $request->payment_method,
                    'payment_date'   => now(),
                    'receipt_no'     => $receiptNo,
                ]);
                $advanceAdded = (float) $request->amount_paid;
                $customer->increment('advance_balance', $advanceAdded);
            }

            $primaryPayment->load(['customer.area', 'collector', 'bill']);
            
            // Attach unified metadata for receipt
            $primaryPayment->receipt_no = $receiptNo;
            $primaryPayment->total_amount_paid = (float) $request->amount_paid;
            $primaryPayment->collected_months = !empty($collectedMonths) ? implode(', ', $collectedMonths) : 'Advance Credit Payment';
            $primaryPayment->advance_added = $advanceAdded;

            return response()->json($primaryPayment, 201);
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

                $unpaidBills = Bill::where('customer_id', $customer->id)
                    ->whereIn('status', ['unpaid', 'partial'])
                    ->orderBy('bill_month', 'asc')
                    ->get();

                $datePrefix = 'RCPT-' . date('Ymd') . '-';
                $lastCount = Payment::where('receipt_no', 'like', $datePrefix . '%')->count();
                $receiptNo = $datePrefix . str_pad($lastCount + 1, 4, '0', STR_PAD_LEFT);

                if ($unpaidBills->isEmpty()) {
                    Payment::create([
                        'bill_id'        => null,
                        'customer_id'    => $customer->id,
                        'collected_by'   => auth()->id() ?? 1,
                        'amount_paid'    => $amountPaid,
                        'payment_method' => $method,
                        'payment_date'   => $payDate,
                        'receipt_no'     => $receiptNo,
                        'notes'          => $notes,
                    ]);
                    $customer->increment('advance_balance', $amountPaid);
                } else {
                    $rem = $amountPaid;
                    foreach ($unpaidBills as $idx => $bill) {
                        if ($rem <= 0) break;
                        $existing = (float) $bill->payments()->sum('amount_paid');
                        $due = max(0, (float) $bill->amount - $existing);
                        if ($due <= 0) continue;

                        $payThis = min($rem, $due);
                        $rem -= $payThis;

                        $itemRcpt = count($unpaidBills) > 1 ? $receiptNo . '-' . ($idx + 1) : $receiptNo;

                        Payment::create([
                            'bill_id'        => $bill->id,
                            'customer_id'    => $customer->id,
                            'collected_by'   => auth()->id() ?? 1,
                            'amount_paid'    => $payThis,
                            'payment_method' => $method,
                            'payment_date'   => $payDate,
                            'receipt_no'     => $itemRcpt,
                            'notes'          => $notes,
                        ]);

                        if (($existing + $payThis) >= (float) $bill->amount) {
                            $bill->update(['status' => 'paid']);
                        } else {
                            $bill->update(['status' => 'partial']);
                        }
                    }

                    if ($rem > 0) {
                        $customer->increment('advance_balance', $rem);
                    }
                }

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
}
