<?php

namespace App\Http\Controllers;

use App\Models\Bill;
use App\Models\Customer;
use App\Models\Payment;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function summary(Request $request)
    {
        $user = $request->user();

        // Customer Counts
        $totalCustomers = Customer::count();
        $activeAnalog   = Customer::where('status', 'active')->where('connection_type', 'analog')->count();
        $activeDigital  = Customer::where('status', 'active')->where('connection_type', 'digital')->count();

        // Total Collections
        $paymentQuery = Payment::query();
        if ($user->hasRole('collector')) {
            $paymentQuery->where('collected_by', $user->id);
        }
        $totalCollected = (float) $paymentQuery->sum('amount_paid');

        // Dues Calculation
        $unpaidDue = (float) Bill::where('status', 'unpaid')->sum('amount');
        $partialBills = Bill::where('status', 'partial')->with('payments')->get();
        $partialDue = $partialBills->sum(fn($b) => max(0, $b->amount - $b->payments->sum('amount_paid')));
        
        $totalDueAmount = $unpaidDue + $partialDue;
        $totalDueCount  = Bill::whereIn('status', ['unpaid', 'partial'])->distinct('customer_id')->count('customer_id');

        // Recent 5 payments
        $recentPaymentsQuery = Payment::with(['customer:id,customer_code,name']);
        if ($user->hasRole('collector')) {
            $recentPaymentsQuery->where('collected_by', $user->id);
        }
        $recentPayments = $recentPaymentsQuery->orderBy('id', 'desc')->take(5)->get();

        return response()->json([
            'totalCustomers' => $totalCustomers,
            'activeAnalog'   => $activeAnalog,
            'activeDigital'  => $activeDigital,
            'totalCollected' => $totalCollected,
            'totalDueAmount' => $totalDueAmount,
            'totalDueCount'  => $totalDueCount,
            'recentPayments' => $recentPayments,
        ]);
    }
}
