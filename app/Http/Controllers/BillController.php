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

            $calculatedPreviousDues = (float) $previousUnpaidBills->sum(function ($pb) {
                $adj = (float) ($pb->adjustment ?? 0);
                $adjEffect = $pb->adjustment_type === 'Debit' ? $adj : ($pb->adjustment_type === 'Credit' ? -$adj : 0);
                return max(0, ((float) $pb->amount + $adjEffect) - (float) $pb->paid_amount);
            });
            $previousDues = $bill->previous_dues !== null ? (float) $bill->previous_dues : $calculatedPreviousDues;

            // Overdue month names e.g. ["May 2026", "June 2026"]
            $previousDueMonths = $previousUnpaidBills->map(function ($b) {
                return Carbon::parse($b->bill_month . '-01')->format('F Y');
            })->values()->toArray();

            $currentAmount = (float) $bill->amount;
            $advanceAdjusted = (float) ($bill->advance ?? 0);
            $advanceCredit = (float) ($customer->advance_balance ?? $customer->advance ?? 0);
            $paidAmount = (float) $bill->paid_amount;
            $adjustment = (float) ($bill->adjustment ?? 0);
            $adjustmentType = $bill->adjustment_type;

            // User formula: (Rent + dues - advance) +/- adjustment
            $adjEffect = $adjustmentType === 'Debit' ? $adjustment : ($adjustmentType === 'Credit' ? -$adjustment : 0);
            $totalBillable = ($currentAmount + $previousDues - $advanceAdjusted) + $adjEffect;
            $netPayable = $bill->status === 'paid' ? 0.00 : max(0, $totalBillable - $paidAmount);

            $rows[] = [
                'id'                  => $bill->id,
                'customer_id'         => $customer->id,
                'customer'            => $customer,
                'bill_month'          => $bill->bill_month,
                'due_date'            => $bill->due_date,
                'amount'              => $currentAmount,
                'paid_amount'         => $paidAmount,
                'due_amount'          => (float) $bill->due_amount,
                'previous_dues'       => $previousDues,
                'previous_due_months' => $previousDueMonths,
                'advance'             => $advanceAdjusted,
                'advance_credit'      => $advanceCredit,
                'adjustment'          => $adjustment,
                'adjustment_type'     => $adjustmentType,
                'net_total_payable'   => $netPayable,
                'status'              => $bill->status,
                'payments'            => $bill->payments,
            ];
        }

        return response()->json($rows);
    }

    public function generate(Request $request)
    {
        if ($request->filled('customer_id')) {
            return $this->generateSingle($request);
        }

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
                $grossAmount = (float) $customer->monthly_rent;

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
                        $grossAmount = ceil($exactAmount / 5) * 5;
                    } else {
                        // Day 1 to 10: Full bill
                        $grossAmount = (float) $customer->monthly_rent;
                    }
                }

                // Check previous dues: first from customer->dues, then from previous unpaid bills
                $previousDues = (float) ($customer->dues ?? 0);
                if ($previousDues <= 0) {
                    $previousDues = (float) Bill::where('customer_id', $customer->id)
                        ->where('bill_month', '<', $billMonth)
                        ->whereIn('status', ['unpaid', 'partial'])
                        ->get()
                        ->sum(function ($pb) {
                            $adj = (float) ($pb->adjustment ?? 0);
                            $adjEffect = $pb->adjustment_type === 'Debit' ? $adj : ($pb->adjustment_type === 'Credit' ? -$adj : 0);
                            return max(0, ((float) $pb->amount + $adjEffect) - (float) $pb->paid_amount);
                        });
                }

                $advanceAvail = (float) ($customer->advance_balance ?? $customer->advance ?? 0);
                $appliedAdvance = min($advanceAvail, $grossAmount + $previousDues);
                $netBillable = ($grossAmount + $previousDues) - $appliedAdvance;

                $bill = Bill::create([
                    'customer_id'     => $customer->id,
                    'bill_month'      => $billMonth,
                    'amount'          => $grossAmount,
                    'previous_dues'   => $previousDues,
                    'advance'         => $appliedAdvance,
                    'adjustment'      => 0.00,
                    'adjustment_type' => null,
                    'due_date'        => $dueDate,
                    'status'          => $netBillable <= 0 ? 'paid' : 'unpaid',
                    'generated_at'    => now(),
                ]);

                // Reset customer standing dues since it has been incorporated into this bill
                if ((float)($customer->dues ?? 0) > 0) {
                    $customer->update(['dues' => 0.00]);
                }

                if ($appliedAdvance > 0) {
                    $customer->decrement('advance_balance', $appliedAdvance);
                    if (\Illuminate\Support\Facades\Schema::hasColumn('customers', 'advance')) {
                        $customer->decrement('advance', $appliedAdvance);
                    }
                    $advanceAdjustedCount++;
                }

                // Sync customer bill statuses
                \App\Http\Controllers\PaymentController::syncCustomerBillStatuses($customer->id);

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

    public function generateSingle(Request $request)
    {
        $request->validate([
            'customer_id'     => 'required|exists:customers,id',
            'bill_month'      => 'required|date_format:Y-m',
            'due_date'        => 'required|date',
            'amount'          => 'nullable|numeric|min:0',
            'previous_dues'   => 'nullable|numeric|min:0',
            'advance'         => 'nullable|numeric|min:0',
            'adjustment'      => 'nullable|numeric|min:0',
            'adjustment_type' => 'nullable|in:Debit,Credit,debit,credit',
        ]);

        $customer = Customer::with('area')->findOrFail($request->customer_id);

        // Check if bill already exists for this customer & month
        $exists = Bill::where('customer_id', $customer->id)
            ->where('bill_month', $request->bill_month)
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "A bill for {$customer->name} ({$customer->customer_code}) for {$request->bill_month} already exists!"
            ], 422);
        }

        $billMonth = $request->bill_month;
        $dueDate = $request->due_date;
        $monthDate = Carbon::parse($billMonth . '-01');
        $totalDaysInMonth = $monthDate->daysInMonth;

        if ($request->filled('amount') && $request->amount !== null && $request->amount !== '') {
            $grossAmount = (float) $request->amount;
        } else {
            $grossAmount = (float) $customer->monthly_rent;
            $connDate = Carbon::parse($customer->connection_date);

            // Prorated rule if joined in the same month
            if ($connDate->format('Y-m') === $billMonth) {
                $connectionDay = $connDate->day;
                if ($connectionDay >= 11) {
                    $activeDays = max(1, $totalDaysInMonth - $connectionDay + 1);
                    $exactAmount = ($customer->monthly_rent / $totalDaysInMonth) * $activeDays;
                    $grossAmount = ceil($exactAmount / 5) * 5;
                }
            }
        }

        // Previous Dues
        if ($request->has('previous_dues') && $request->previous_dues !== '' && $request->previous_dues !== null) {
            $customPreviousDues = (float) $request->previous_dues;
        } else {
            $customPreviousDues = (float) ($customer->dues ?? 0);
            if ($customPreviousDues <= 0) {
                $customPreviousDues = (float) Bill::where('customer_id', $customer->id)
                    ->where('bill_month', '<', $billMonth)
                    ->whereIn('status', ['unpaid', 'partial'])
                    ->get()
                    ->sum(function ($pb) {
                        $adj = (float) ($pb->adjustment ?? 0);
                        $adjEffect = $pb->adjustment_type === 'Debit' ? $adj : ($pb->adjustment_type === 'Credit' ? -$adj : 0);
                        return max(0, ((float) $pb->amount + $adjEffect) - (float) $pb->paid_amount);
                    });
            }
        }

        // Advance
        if ($request->has('advance') && $request->advance !== '' && $request->advance !== null) {
            $appliedAdvance = (float) $request->advance;
        } else {
            $advanceAvail = (float) ($customer->advance_balance ?? $customer->advance ?? 0);
            $appliedAdvance = min($advanceAvail, $grossAmount + $customPreviousDues);
        }

        // Adjustment
        $adjustment = (float) ($request->adjustment ?? 0);
        $adjustmentType = $request->filled('adjustment_type') ? ucfirst(strtolower($request->adjustment_type)) : null;
        $adjEffect = $adjustmentType === 'Debit' ? $adjustment : ($adjustmentType === 'Credit' ? -$adjustment : 0);

        $netBillable = ($grossAmount + $customPreviousDues - $appliedAdvance) + $adjEffect;
        $status = $netBillable <= 0 ? 'paid' : 'unpaid';

        $bill = DB::transaction(function () use ($customer, $billMonth, $grossAmount, $customPreviousDues, $appliedAdvance, $adjustment, $adjustmentType, $dueDate, $status) {
            $b = Bill::create([
                'customer_id'     => $customer->id,
                'bill_month'      => $billMonth,
                'amount'          => $grossAmount,
                'previous_dues'   => $customPreviousDues,
                'advance'         => $appliedAdvance,
                'adjustment'      => $adjustment,
                'adjustment_type' => $adjustmentType,
                'due_date'        => $dueDate,
                'status'          => $status,
                'generated_at'    => now(),
            ]);

            if ((float)($customer->dues ?? 0) > 0) {
                $customer->update(['dues' => 0.00]);
            }

            if ($appliedAdvance > 0) {
                $customer->decrement('advance_balance', $appliedAdvance);
                if (\Illuminate\Support\Facades\Schema::hasColumn('customers', 'advance')) {
                    $customer->decrement('advance', $appliedAdvance);
                }
            }

            \App\Http\Controllers\PaymentController::syncCustomerBillStatuses($customer->id);

            return $b;
        });

        return response()->json([
            'message' => "Bill for {$customer->name} ({$billMonth}) generated successfully!",
            'bill'    => $bill->fresh(['customer.area', 'payments']),
        ], 201);
    }

    public function customerBills(Customer $customer)
    {
        $bills = $customer->bills()->with('payments')->orderBy('bill_month', 'desc')->get();
        return response()->json($bills);
    }

    public function exportExcel(Request $request)
    {
        $billMonth = $request->input('bill_month');
        if (!$billMonth) {
            $billMonth = Bill::max('bill_month');
        }

        $query = Bill::with(['customer.area', 'payments']);

        if ($billMonth) {
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
            'Monthly Rent (' . ($billMonth ?: 'All') . ')',
            'Previous Dues (Tk)',
            'Advance Adjusted (Tk)',
            'Adjustment (Tk)',
            'Paid Amount (Tk)',
            'Total Payable Amount (Tk)',
            'Payment Status',
            'Collector Signature / Notes'
        ];

        $sheet->fromArray($headers, null, 'A1');

        // Header Styling: Emerald Background with Bold White Text
        $headerRange = 'A1:P1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF059669');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $rowIdx = 2;
        $sl = 1;

        foreach ($bills as $bill) {
            $customer = $bill->customer;
            if (!$customer) continue;

            $previousUnpaidBills = Bill::where('customer_id', $customer->id)
                ->where('id', '!=', $bill->id)
                ->where('bill_month', '<', $bill->bill_month)
                ->whereIn('status', ['unpaid', 'partial'])
                ->orderBy('bill_month', 'asc')
                ->get();

            $calculatedPreviousDues = (float) $previousUnpaidBills->sum(function ($pb) {
                $adj = (float) ($pb->adjustment ?? 0);
                $adjEffect = $pb->adjustment_type === 'Debit' ? $adj : ($pb->adjustment_type === 'Credit' ? -$adj : 0);
                return max(0, ((float) $pb->amount + $adjEffect) - (float) $pb->paid_amount);
            });
            $previousDues = $bill->previous_dues !== null ? (float) $bill->previous_dues : $calculatedPreviousDues;

            $currentBillAmount = (float) $bill->amount;
            $advanceAdjusted = (float) ($bill->advance ?? 0);
            $paidAmount = (float) $bill->paid_amount;
            $adjustment = (float) ($bill->adjustment ?? 0);
            $adjustmentType = $bill->adjustment_type;
            $adjEffect = $adjustmentType === 'Debit' ? $adjustment : ($adjustmentType === 'Credit' ? -$adjustment : 0);

            $adjDisplay = $adjustment > 0 ? (($adjustmentType === 'Debit' ? '+' : '-') . number_format($adjustment, 2)) : '0.00';

            // User formula: (Rent + dues - advance) +/- adjustment
            $totalBillable = ($currentBillAmount + $previousDues - $advanceAdjusted) + $adjEffect;
            $totalPayable = max(0, $totalBillable - $paidAmount);

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
                $advanceAdjusted,
                $adjDisplay,
                $paidAmount,
                $totalPayable,
                strtoupper($bill->status),
                ''
            ];

            $sheet->fromArray($rowData, null, 'A' . $rowIdx, true);

            // Format Currency cells
            $sheet->getStyle('I' . $rowIdx . ':N' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');

            $rowIdx++;
        }

        // Summary Total Row
        if ($rowIdx > 2) {
            $summaryRow = [
                '',
                'TOTAL',
                '',
                '',
                '',
                '',
                '',
                '',
                '=SUM(I2:I' . ($rowIdx - 1) . ')',
                '=SUM(J2:J' . ($rowIdx - 1) . ')',
                '=SUM(K2:K' . ($rowIdx - 1) . ')',
                '',
                '=SUM(M2:M' . ($rowIdx - 1) . ')',
                '=SUM(N2:N' . ($rowIdx - 1) . ')',
                '',
                ''
            ];
            $sheet->fromArray($summaryRow, null, 'A' . $rowIdx);
            $summaryRange = 'A' . $rowIdx . ':P' . $rowIdx;
            $sheet->getStyle($summaryRange)->getFont()->setBold(true);
            $sheet->getStyle($summaryRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFF1F5F9');
            $sheet->getStyle('I' . $rowIdx . ':N' . $rowIdx)->getNumberFormat()->setFormatCode('#,##0.00');
            $sheet->getRowDimension($rowIdx)->setRowHeight(24);
        }

        // Auto-fit column widths
        foreach (range('A', 'P') as $col) {
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

    public function update(Request $request, Bill $bill)
    {
        if (!$request->user()->hasRole(['super_admin', 'accounts'])) {
            return response()->json(['message' => 'Unauthorized. Only Super Admin and Accounts can edit bills.'], 403);
        }

        $request->validate([
            'amount'          => 'required|numeric|min:0',
            'previous_dues'   => 'nullable|numeric|min:0',
            'advance'         => 'nullable|numeric|min:0',
            'adjustment'      => 'nullable|numeric|min:0',
            'adjustment_type' => 'nullable|in:Debit,Credit,debit,credit',
            'due_date'        => 'required|date',
            'status'          => 'required|in:unpaid,partial,paid',
        ]);

        $adjustmentType = $request->filled('adjustment_type') ? ucfirst(strtolower($request->adjustment_type)) : null;

        $bill->update([
            'amount'          => $request->amount,
            'previous_dues'   => $request->has('previous_dues') && $request->previous_dues !== '' ? $request->previous_dues : 0,
            'advance'         => $request->has('advance') && $request->advance !== '' ? $request->advance : 0,
            'adjustment'      => $request->has('adjustment') && $request->adjustment !== '' ? $request->adjustment : 0,
            'adjustment_type' => $adjustmentType,
            'due_date'        => $request->due_date,
            'status'          => $request->status,
        ]);

        return response()->json([
            'message' => 'Bill updated successfully!',
            'bill'    => $bill->fresh(['customer.area', 'payments']),
        ]);
    }

    public function destroy(Request $request, Bill $bill)
    {
        if (!$request->user()->hasRole('super_admin')) {
            return response()->json(['message' => 'Unauthorized. Only Super Admin can delete bills.'], 403);
        }

        DB::transaction(function () use ($bill) {
            $bill->payments()->delete();
            $bill->delete();
        });

        return response()->json(['message' => 'Bill deleted successfully!']);
    }
}
