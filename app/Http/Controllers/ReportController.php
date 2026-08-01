<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Deposit;
use App\Models\Payment;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function collectionSummary(Request $request)
    {
        $user = $request->user();
        $query = Payment::with(['customer.area', 'collector']);

        if ($user->hasRole('collector')) {
            $query->where('collected_by', $user->id);
        } elseif ($request->filled('collector_id')) {
            $query->where('collected_by', $request->collector_id);
        }

        if ($request->filled('area_id')) {
            $query->whereHas('customer', function ($q) use ($request) {
                $q->where('area_id', $request->area_id);
            });
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('payment_date', [
                $request->start_date . ' 00:00:00',
                $request->end_date . ' 23:59:59',
            ]);
        }

        $payments = $query->orderBy('payment_date', 'desc')->get();
        $totalCollected = $payments->sum('amount_paid');
        $cashTotal = $payments->where('payment_method', 'cash')->sum('amount_paid');
        $digitalTotal = $totalCollected - $cashTotal;

        return response()->json([
            'total_collected' => $totalCollected,
            'cash_total'      => $cashTotal,
            'digital_total'   => $digitalTotal,
            'count'           => $payments->count(),
            'payments'        => $payments,
        ]);
    }

    public function dueCustomers(Request $request)
    {
        $user = $request->user();
        $query = Customer::with(['area', 'collector', 'bills' => function ($q) {
            $q->whereIn('status', ['unpaid', 'partial']);
        }]);

        if ($user->hasRole('collector')) {
            $query->where(function ($q) use ($user) {
                $q->where('assigned_collector_id', $user->id);
                if ($user->area_id) {
                    $q->orWhere('area_id', $user->area_id);
                }
            });
        } elseif ($request->filled('collector_id')) {
            $query->where('assigned_collector_id', $request->collector_id);
        }

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        $customers = $query->get()->filter(function ($c) {
            return $c->total_due > 0;
        })->values();

        $totalDueAmount = $customers->sum('total_due');

        return response()->json([
            'total_due_customers' => $customers->count(),
            'total_due_amount'    => $totalDueAmount,
            'customers'           => $customers,
        ]);
    }

    public function depositLedger(Request $request)
    {
        $query = Deposit::with(['customer.area', 'creator']);

        if ($request->filled('area_id')) {
            $query->whereHas('customer', function ($q) use ($request) {
                $q->where('area_id', $request->area_id);
            });
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $deposits = $query->orderBy('date', 'desc')->get();
        $totalCollected = $deposits->where('type', 'collected')->sum('amount');
        $totalRefunded = $deposits->where('type', 'refunded')->sum('amount');

        return response()->json([
            'total_collected' => $totalCollected,
            'total_refunded'  => $totalRefunded,
            'net_deposit'     => $totalCollected - $totalRefunded,
            'deposits'        => $deposits,
        ]);
    }
}
