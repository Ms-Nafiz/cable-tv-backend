<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_code',
        'name',
        'phone',
        'address',
        'area_id',
        'connection_type',
        'stb_serial',
        'monthly_rent',
        'deposit_amount',
        'dues',
        'advance',
        'advance_balance',
        'connection_date',
        'status',
        'assigned_collector_id',
    ];

    protected $casts = [
        'monthly_rent' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
        'dues' => 'decimal:2',
        'advance' => 'decimal:2',
        'advance_balance' => 'decimal:2',
        'connection_date' => 'date',
    ];

    protected $appends = ['total_due', 'current_deposit'];

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'assigned_collector_id');
    }

    public function deposits()
    {
        return $this->hasMany(Deposit::class);
    }

    public function bills()
    {
        return $this->hasMany(Bill::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getTotalDueAttribute()
    {
        $firstBill = $this->bills()->orderBy('bill_month', 'asc')->orderBy('id', 'asc')->first();
        $openingDues = (float) ($this->dues ?? 0) + ($firstBill ? (float) ($firstBill->previous_dues ?? 0) : 0);

        $bills = $this->bills()->get();
        $totalBilled = 0;
        foreach ($bills as $bill) {
            $amt = (float) $bill->amount;
            $adj = (float) ($bill->adjustment ?? 0);
            $adjEffect = $bill->adjustment_type === 'Debit' ? $adj : ($bill->adjustment_type === 'Credit' ? -$adj : 0);
            $totalBilled += ($amt + $adjEffect);
        }

        $totalDebits = $openingDues + $totalBilled;
        $totalPaid = (float) $this->payments()->sum('amount_paid');
        $advanceBalance = (float) ($this->advance_balance ?? 0);

        return max(0, $totalDebits - $totalPaid - $advanceBalance);
    }

    public function getCurrentDepositAttribute()
    {
        $collected = $this->deposits()->where('type', 'collected')->sum('amount');
        $refunded = $this->deposits()->where('type', 'refunded')->sum('amount');
        return max(0, $collected - $refunded);
    }
}
