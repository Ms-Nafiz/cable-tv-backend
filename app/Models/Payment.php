<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'bill_id',
        'customer_id',
        'collected_by',
        'amount_paid',
        'payment_method',
        'payment_date',
        'receipt_no',
        'notes',
    ];

    protected $casts = [
        'amount_paid' => 'decimal:2',
        'payment_date' => 'datetime',
    ];

    public function bill()
    {
        return $this->belongsTo(Bill::class);
    }

    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function collector()
    {
        return $this->belongsTo(User::class, 'collected_by');
    }
}
