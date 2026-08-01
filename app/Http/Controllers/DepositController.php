<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Deposit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DepositController extends Controller
{
    public function index(Customer $customer)
    {
        $deposits = $customer->deposits()->with('creator')->orderBy('date', 'desc')->get();
        return response()->json($deposits);
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'amount'      => 'required|numeric|min:0.01',
            'type'        => 'required|in:collected,refunded',
            'date'        => 'required|date',
            'remarks'     => 'nullable|string',
        ]);

        $deposit = Deposit::create([
            'customer_id' => $request->customer_id,
            'amount'      => $request->amount,
            'type'        => $request->type,
            'date'        => $request->date,
            'remarks'     => $request->remarks,
            'created_by'  => auth()->id(),
        ]);

        return response()->json($deposit->load('creator'), 201);
    }

    public function refund(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'date'        => 'required|date',
            'remarks'     => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request) {
            $customer = Customer::findOrFail($request->customer_id);

            $currentDeposit = $customer->current_deposit;
            $totalDue = $customer->total_due;

            $netRefund = max(0, $currentDeposit - $totalDue);
            $deductedDue = min($currentDeposit, $totalDue);

            // Record refund entry
            $deposit = Deposit::create([
                'customer_id' => $customer->id,
                'amount'      => $currentDeposit,
                'type'        => 'refunded',
                'date'        => $request->date,
                'remarks'     => "Disconnection Refund summary: Gross Deposit ৳{$currentDeposit}, Deducted Due ৳{$deductedDue}, Net Refund ৳{$netRefund}. " . ($request->remarks ?? ''),
                'created_by'  => auth()->id(),
            ]);

            // Set customer status to disconnected
            $customer->update(['status' => 'disconnected']);

            return response()->json([
                'message'          => 'Deposit refund processed and customer disconnected',
                'deposit'          => $deposit,
                'gross_deposit'    => $currentDeposit,
                'deducted_due'     => $deductedDue,
                'net_refund'       => $netRefund,
                'remaining_due'    => max(0, $totalDue - $currentDeposit),
            ]);
        });
    }
}
