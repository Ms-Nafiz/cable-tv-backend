<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasFactory;

    protected $fillable = ['name'];

    public function customers()
    {
        return $this->hasMany(Customer::class);
    }

    public function collectors()
    {
        return $this->hasMany(User::class);
    }
}
