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
        'advance_balance',
        'connection_date',
        'status',
        'assigned_collector_id',
    ];

    protected $casts = [
        'monthly_rent' => 'decimal:2',
        'deposit_amount' => 'decimal:2',
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
        $totalBilled = $this->bills()->sum('amount');
        $totalPaid = $this->payments()->sum('amount_paid');
        $grossDue = max(0, $totalBilled - $totalPaid);
        return max(0, $grossDue - (float) $this->advance_balance);
    }

    public function getCurrentDepositAttribute()
    {
        $collected = $this->deposits()->where('type', 'collected')->sum('amount');
        $refunded = $this->deposits()->where('type', 'refunded')->sum('amount');
        return max(0, $collected - $refunded);
    }
}
