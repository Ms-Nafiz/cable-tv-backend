<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('customer_code')->unique();
            $table->string('name');
            $table->string('phone');
            $table->text('address')->nullable();
            $table->foreignId('area_id')->constrained('areas')->cascadeOnDelete();
            $table->enum('connection_type', ['analog', 'digital'])->default('analog');
            $table->string('stb_serial')->nullable();
            $table->decimal('monthly_rent', 10, 2);
            $table->decimal('deposit_amount', 10, 2)->default(500.00);
            $table->date('connection_date');
            $table->enum('status', ['active', 'inactive', 'disconnected'])->default('active');
            $table->foreignId('assigned_collector_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
