<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bill extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'bill_month',
        'amount',
        'previous_dues',
        'advance',
        'adjustment',
        'adjustment_type',
        'due_date',
        'status',
        'generated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'previous_dues' => 'decimal:2',
        'advance' => 'decimal:2',
        'adjustment' => 'decimal:2',
        'due_date' => 'date',
        'generated_at' => 'datetime',
    ];

    protected $appends = ['paid_amount', 'due_amount'];

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class);
    }

    public function getPaidAmountAttribute()
    {
        return (float) $this->payments()->sum('amount_paid');
    }

    public function getDueAmountAttribute()
    {
        if ($this->status === 'paid') {
            return 0.00;
        }

        $rent = (float) $this->amount;
        $dues = (float) ($this->previous_dues ?? 0);
        $advance = (float) ($this->advance ?? 0);
        $adj = (float) ($this->adjustment ?? 0);
        $adjEffect = $this->adjustment_type === 'Debit' ? $adj : ($this->adjustment_type === 'Credit' ? -$adj : 0);

        $totalBillable = ($rent + $dues - $advance) + $adjEffect;
        return max(0, $totalBillable - $this->paid_amount);
    }
}
