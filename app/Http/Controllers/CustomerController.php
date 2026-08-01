<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Area;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $query = Customer::with(['area', 'collector', 'bills.payments', 'payments']);

        // All users including collectors can view all customers

        // Filters
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('connection_type')) {
            $query->where('connection_type', $request->connection_type);
        }

        $customers = $query->orderBy('id', 'desc')->get();

        return response()->json($customers);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'                  => 'required|string|max:255',
            'phone'                 => 'required|string|max:20',
            'address'               => 'nullable|string',
            'area_id'               => 'required|exists:areas,id',
            'connection_type'       => 'required|in:analog,digital',
            'stb_serial'            => 'nullable|string',
            'monthly_rent'          => 'required|numeric|min:0',
            'deposit_amount'        => 'required|numeric|min:0',
            'connection_date'       => 'required|date',
            'assigned_collector_id' => 'nullable|exists:users,id',
        ]);

        return DB::transaction(function () use ($request) {
            // Generate Code
            $lastId = Customer::max('id') ?? 0;
            $customerCode = 'CCL' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);

            $customer = Customer::create([
                'customer_code'         => $customerCode,
                'name'                  => $request->name,
                'phone'                 => $request->phone,
                'address'               => $request->address,
                'area_id'               => $request->area_id,
                'connection_type'       => $request->connection_type,
                'stb_serial'            => $request->stb_serial,
                'monthly_rent'          => $request->monthly_rent,
                'deposit_amount'        => $request->deposit_amount,
                'connection_date'       => $request->connection_date,
                'status'                => 'active',
                'assigned_collector_id' => $request->assigned_collector_id,
            ]);

            // Entry in deposits table
            if ($request->deposit_amount > 0) {
                Deposit::create([
                    'customer_id' => $customer->id,
                    'amount'      => $request->deposit_amount,
                    'type'        => 'collected',
                    'date'        => $request->connection_date,
                    'remarks'     => 'Initial Security Deposit (' . ucfirst($request->connection_type) . ')',
                    'created_by'  => auth()->id(),
                ]);
            }

            return response()->json($customer->load(['area', 'collector']), 201);
        });
    }

    public function show(Customer $customer)
    {
        $customer->load([
            'area',
            'collector',
            'deposits.creator',
            'bills' => function ($q) {
                $q->with('payments.collector')->orderBy('bill_month', 'asc');
            },
            'payments.collector',
        ]);

        return response()->json($customer);
    }

    public function update(Request $request, Customer $customer)
    {
        $request->validate([
            'name'                  => 'required|string|max:255',
            'phone'                 => 'required|string|max:20',
            'address'               => 'nullable|string',
            'area_id'               => 'required|exists:areas,id',
            'connection_type'       => 'required|in:analog,digital',
            'stb_serial'            => 'nullable|string',
            'monthly_rent'          => 'required|numeric|min:0',
            'connection_date'       => 'required|date',
            'status'                => 'required|in:active,inactive,disconnected',
            'assigned_collector_id' => 'nullable|exists:users,id',
        ]);

        $customer->update([
            'name'                  => $request->name,
            'phone'                 => $request->phone,
            'address'               => $request->address,
            'area_id'               => $request->area_id,
            'connection_type'       => $request->connection_type,
            'stb_serial'            => $request->stb_serial,
            'monthly_rent'          => $request->monthly_rent,
            'connection_date'       => $request->connection_date,
            'status'                => $request->status,
            'assigned_collector_id' => $request->assigned_collector_id,
        ]);

        return response()->json($customer->load(['area', 'collector']));
    }

    public function destroy(Customer $customer)
    {
        $customer->delete();
        return response()->json(['message' => 'Customer deleted successfully']);
    }

    public function toggleStatus(Customer $customer)
    {
        $newStatus = ($customer->status === 'active') ? 'inactive' : 'active';
        $customer->update(['status' => $newStatus]);

        return response()->json([
            'message'  => "Customer {$customer->name} status changed to {$newStatus}.",
            'customer' => $customer->fresh(['area', 'collector']),
        ]);
    }

    public function downloadSampleExcel()
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Bulk Customer Import');

        $headers = [
            'Subscriber Name *',
            'Phone Number *',
            'Address',
            'Area Zone Name *',
            'Connection Type (analog/digital) *',
            'STB Serial',
            'Monthly Rent *',
            'Security Deposit *',
            'Connection Date (YYYY-MM-DD) *'
        ];

        $sheet->fromArray($headers, null, 'A1');

        $headerRange = 'A1:I1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF059669');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(28);

        // Sample Rows
        $sampleData = [
            ['Md. Anowar Hossain', '01711223344', 'House 42, Road 7', 'Dhanmondi Zone', 'analog', '', '500', '500', '2026-05-10'],
            ['Sharmin Akter', '01899887766', 'Flat 4B, Building 12', 'Gulshan Zone', 'digital', 'STB-998811', '800', '1000', '2026-06-15'],
        ];

        $sheet->fromArray($sampleData, null, 'A2');

        foreach (range('A', 'I') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, 'Sample_Bulk_Customer_Import_Template.xlsx', [
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

                $name           = trim($row['A'] ?? '');
                $phone          = trim($row['B'] ?? '');
                $address        = trim($row['C'] ?? '');
                $areaName       = trim($row['D'] ?? '');
                $connType       = strtolower(trim($row['E'] ?? 'analog'));
                $stbSerial      = trim($row['F'] ?? '');
                $monthlyRent    = (float) ($row['G'] ?? 500);
                $depositAmount  = (float) ($row['H'] ?? 500);
                $connDateRaw    = trim($row['I'] ?? '');

                if (empty($name) || empty($phone) || empty($areaName)) {
                    $skippedCount++;
                    continue;
                }

                if (Customer::where('phone', $phone)->exists()) {
                    $skippedCount++;
                    continue;
                }

                $area = Area::firstOrCreate(['name' => $areaName]);
                $connDate = !empty($connDateRaw) ? date('Y-m-d', strtotime($connDateRaw)) : date('Y-m-d');

                $lastId = Customer::max('id') ?? 0;
                $customerCode = 'CCL' . str_pad($lastId + 1, 5, '0', STR_PAD_LEFT);

                $customer = Customer::create([
                    'customer_code'   => $customerCode,
                    'name'            => $name,
                    'phone'           => $phone,
                    'address'         => $address,
                    'area_id'         => $area->id,
                    'connection_type' => in_array($connType, ['analog', 'digital']) ? $connType : 'analog',
                    'stb_serial'      => !empty($stbSerial) ? $stbSerial : null,
                    'monthly_rent'    => $monthlyRent,
                    'deposit_amount'  => $depositAmount,
                    'connection_date' => $connDate,
                    'status'          => 'active',
                ]);

                if ($depositAmount > 0) {
                    Deposit::create([
                        'customer_id' => $customer->id,
                        'amount'      => $depositAmount,
                        'type'        => 'collected',
                        'date'        => $connDate,
                        'remarks'     => 'Initial Security Deposit (Excel Bulk Import)',
                        'created_by'  => auth()->id() ?? 1,
                    ]);
                }

                $importedCount++;
            }
        });

        return response()->json([
            'message'        => "Bulk Customer Import Completed Successfully!",
            'imported_count' => $importedCount,
            'skipped_count'  => $skippedCount,
        ]);
    }

    public function exportExcel(Request $request)
    {
        $query = Customer::with(['area', 'bills']);

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_code', 'like', "%{$search}%")
                  ->orWhere('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $customers = $query->orderBy('customer_code', 'asc')->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Subscriber Registry');

        $headers = [
            'Customer Code',
            'Subscriber Name',
            'Phone Number',
            'Address',
            'Area Zone',
            'Connection Type',
            'STB Serial',
            'Monthly Rent (Tk)',
            'Total Due Dues (Tk)',
            'Security Deposit (Tk)',
            'Advance Balance (Tk)',
            'Status',
            'Connection Date'
        ];

        $sheet->fromArray($headers, null, 'A1');

        $headerRange = 'A1:M1';
        $sheet->getStyle($headerRange)->getFont()->setBold(true)->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FFFFFFFF'));
        $sheet->getStyle($headerRange)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF059669');
        $sheet->getStyle($headerRange)->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        $sheet->getRowDimension(1)->setRowHeight(28);

        $rowNum = 2;
        foreach ($customers as $c) {
            $totalDue = $c->bills->whereIn('status', ['unpaid', 'partial'])->sum('due_amount');

            $data = [
                $c->customer_code,
                $c->name,
                $c->phone,
                $c->address ?? '',
                $c->area ? $c->area->name : '',
                strtoupper($c->connection_type),
                $c->stb_serial ?? '-',
                (float) $c->monthly_rent,
                (float) $totalDue,
                (float) $c->deposit_amount,
                (float) ($c->advance_balance ?? 0),
                strtoupper($c->status),
                $c->connection_date,
            ];

            $sheet->fromArray($data, null, 'A' . $rowNum);

            $sheet->getStyle('H' . $rowNum . ':K' . $rowNum)
                ->getNumberFormat()
                ->setFormatCode('#,##0.00');

            $rowNum++;
        }

        foreach (range('A', 'M') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $filename = 'Subscriber_Registry_Export_' . date('Y-m-d') . '.xlsx';

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'max-age=0',
        ]);
    }
}
