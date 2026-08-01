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
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class BillController extends Controller
{
    public function index(Request $request)
    {
        // Target Month: Selected bill_month or latest generated bill_month in DB
        $targetMonth = $request->input('bill_month');
        if (!$targetMonth) {
            $targetMonth = Bill::max('bill_month');
        }

        if (!$targetMonth) {
            return response()->json([]);
        }

        // Fetch ONLY bills generated for that target month
        $query = Bill::with(['customer.area', 'customer.collector', 'payments.collector'])
            ->where('bill_month', $targetMonth);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('area_id')) {
            $query->whereHas('customer', function ($q) use ($request) {
                $q->where('area_id', $request->area_id);
            });
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('customer_code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $bills = $query->orderBy('id', 'desc')->get();

        $rows = [];

        foreach ($bills as $bill) {
            $customer = $bill->customer;
            if (!$customer) continue;

            // Find all unpaid/partial previous bills BEFORE this bill month
            $previousUnpaidBills = Bill::where('customer_id', $customer->id)
                ->where('id', '!=', $bill->id)
                ->where('bill_month', '<', $bill->bill_month)
                ->whereIn('status', ['unpaid', 'partial'])
                ->orderBy('bill_month', 'asc')
                ->get();

            $previousDues = (float) $previousUnpaidBills->sum('due_amount');

            // Overdue month names e.g. ["May 2026", "June 2026"]
            $previousDueMonths = $previousUnpaidBills->map(function ($b) {
                return Carbon::parse($b->bill_month . '-01')->format('F Y');
            })->values()->toArray();

            $currentAmount = (float) $bill->amount;
            $advanceCredit = (float) ($customer->advance_balance ?? 0);
            $netPayable = max(0, ($currentAmount + $previousDues) - $advanceCredit);

            $rows[] = [
                'id'                  => $bill->id,
                'customer_id'         => $customer->id,
                'customer'            => $customer,
                'bill_month'          => $bill->bill_month,
                'due_date'            => $bill->due_date,
                'amount'              => $currentAmount,
                'paid_amount'         => (float) $bill->paid_amount,
                'due_amount'          => (float) $bill->due_amount,
                'previous_dues'       => $previousDues,
                'previous_due_months' => $previousDueMonths,
                'advance_credit'      => $advanceCredit,
                'net_total_payable'   => $netPayable,
                'status'              => $bill->status,
                'payments'            => $bill->payments,
            ];
        }

        return response()->json($rows);
    }

    public function generate(Request $request)
    {
        $request->validate([
            'bill_month' => 'required|date_format:Y-m',
            'due_date'   => 'required|date',
        ]);

        $billMonth = $request->bill_month;
        $dueDate = $request->due_date;
        $monthDate = Carbon::parse($billMonth . '-01');
        $totalDaysInMonth = $monthDate->daysInMonth;

        $activeCustomers = Customer::where('status', 'active')->get();

        $generatedCount = 0;
        $skippedCount = 0;
        $advanceAdjustedCount = 0;

        DB::transaction(function () use ($activeCustomers, $billMonth, $dueDate, $monthDate, $totalDaysInMonth, &$generatedCount, &$skippedCount, &$advanceAdjustedCount) {
            foreach ($activeCustomers as $customer) {
                // Ensure only ACTIVE customers get billed (skip inactive/disconnected)
                if ($customer->status !== 'active') {
                    $skippedCount++;
                    continue;
                }

                // Check if bill already exists for this month
                $exists = Bill::where('customer_id', $customer->id)
                    ->where('bill_month', $billMonth)
                    ->exists();

                if ($exists) {
                    $skippedCount++;
                    continue;
                }

                $connDate = Carbon::parse($customer->connection_date);
                $amount = $customer->monthly_rent;

                // Business Rules for Connection Date in the Same Month:
                // 1. Connection Day 1-10: Full Bill
                // 2. Connection Day 11-20: Prorated Bill (Round UP to nearest 5 Taka step)
                // 3. Connection Day 21-31: NO BILL generated for this month!
                if ($connDate->format('Y-m') === $billMonth) {
                    $connectionDay = $connDate->day;

                    if ($connectionDay >= 21) {
                        // Day 21 to 31: Skip bill generation for current month
                        $skippedCount++;
                        continue;
                    } elseif ($connectionDay >= 11 && $connectionDay <= 20) {
                        // Day 11 to 20: Prorated bill
                        $activeDays = max(1, $totalDaysInMonth - $connectionDay + 1);
                        $exactAmount = ($customer->monthly_rent / $totalDaysInMonth) * $activeDays;
                        $amount = ceil($exactAmount / 5) * 5;
                    } else {
                        // Day 1 to 10: Full bill
                        $amount = $customer->monthly_rent;
                    }
                }

                $bill = Bill::create([
                    'customer_id'  => $customer->id,
                    'bill_month'   => $billMonth,
                    'amount'       => $amount,
                    'due_date'     => $dueDate,
                    'status'       => 'unpaid',
                    'generated_at' => now(),
                ]);

                // Auto-adjust from Advance Credit Balance if customer has advance funds!
                if ($customer->advance_balance > 0) {
                    $advanceAvail = (float) $customer->advance_balance;
                    $applyAmount = min($advanceAvail, (float) $amount);

                    $datePrefix = 'RCPT-ADV-' . date('Ymd') . '-';
                    $countToday = Payment::where('receipt_no', 'like', $datePrefix . '%')->count();
                    $advReceiptNo = $datePrefix . str_pad($countToday + 1, 4, '0', STR_PAD_LEFT);

                    Payment::create([
                        'bill_id'        => $bill->id,
                        'customer_id'    => $customer->id,
                        'collected_by'   => auth()->id() ?? 1,
                        'amount_paid'    => $applyAmount,
                        'payment_method' => 'cash',
                        'payment_date'   => now(),
                        'receipt_no'     => $advReceiptNo,
                    ]);

                    $customer->decrement('advance_balance', $applyAmount);

                    if ($applyAmount >= (float) $amount) {
                        $bill->update(['status' => 'paid']);
                    } else {
                        $bill->update(['status' => 'partial']);
                    }

                    $advanceAdjustedCount++;
                }

                $generatedCount++;
            }
        });

        return response()->json([
            'message'                => "Billing generation complete for {$billMonth}.",
            'generated_count'        => $generatedCount,
            'skipped_count'          => $skippedCount,
            'advance_adjusted_count' => $advanceAdjustedCount,
        ]);
    }

    public function customerBills(Customer $customer)
    {
        $bills = $customer->bills()->with('payments')->orderBy('bill_month', 'desc')->get();
        return response()->json($bills);
    }

    public function exportExcel(Request $request)
    {
        $billMonth = $request->input('bill_month', date('Y-m'));

        $query = Bill::with(['customer.area', 'payments']);

        if ($request->filled('bill_month')) {
            $query->where('bill_month', $billMonth);
        }

        if ($request->filled('area_id')) {
            $query->whereHas('customer', function ($q) use ($request) {
                $q->where('area_id', $request->area_id);
            });
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->whereHas('customer', function ($q) use ($search) {
                $q->where('customer_code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $bills = $query->orderBy('bill_month', 'desc')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Collection Bill Sheet');

        // Headers
        $headers = [
            'SL',
            'Customer Code',
            'Subscriber Name',
            'Phone',
            'Area Zone',
            'Address',
            'Connection Type',
            'STB Serial',
            'Current Month Bill (' . $billMonth . ')',
            'Previous Dues (Tk)',
            'Advance Credit (Tk)',
            'Total Payable Amount (Tk)',
            'Payment Status',
            'Collector Signature / Notes'
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Header Styling: Emerald Background with Bold White Text
        $headerRange = 'A1:N1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF059669');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $rowIdx = 2;
        $sl = 1;

        foreach ($bills as $bill) {
            $customer = $bill->customer;
            if (!$customer) continue;

            $currentBillAmount = (float) $bill->amount;

            $previousDues = Bill::where('customer_id', $customer->id)
                ->where('id', '!=', $bill->id)
                ->where('bill_month', '<', $bill->bill_month)
                ->whereIn('status', ['unpaid', 'partial'])
                ->get()
                ->sum('due_amount');

            $advanceCredit = (float) ($customer->advance_balance ?? 0);
            $totalPayable = max(0, ($currentBillAmount + $previousDues) - $advanceCredit);

            $rowData = [
                $sl++,
                $customer->customer_code,
                $customer->name,
                $customer->phone,
                $customer->area ? $customer->area->name : '',
                $customer->address,
                strtoupper($customer->connection_type),
                $customer->stb_serial ?? '-',
                $currentBillAmount,
                $previousDues,
                $advanceCredit,
                $totalPayable,
                strtoupper($bill->status),
                ''
            ];

            $sheet->fromArray($rowData, null, 'A' . $rowIdx);

            // Format Currency cells
            $sheet->getStyle('I' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('J' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('K' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getStyle('L' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');

            $rowIdx++;
        }

        // Auto-fit column widths
        foreach (range('A', 'N') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $fileName = 'Bill_Sheet_' . ($billMonth ? $billMonth : 'All') . '_' . date('Ymd_His') . '.xlsx';

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $fileName, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
