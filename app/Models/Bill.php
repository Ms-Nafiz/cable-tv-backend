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
        'due_date',
        'status',
        'generated_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'previous_dues' => 'decimal:2',
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
        $totalBillable = (float) $this->amount + (float) ($this->previous_dues ?? 0);
        return max(0, $totalBillable - $this->paid_amount);
    }
}
