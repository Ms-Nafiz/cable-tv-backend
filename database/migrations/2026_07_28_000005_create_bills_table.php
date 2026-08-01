<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('bill_month', 10);
            $table->decimal('amount', 10, 2);
            $table->date('due_date');
            $table->enum('status', ['unpaid', 'partial', 'paid'])->default('unpaid');
            $table->timestamp('generated_at')->nullable();
            $table->unique(['customer_id', 'bill_month']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
