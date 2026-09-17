<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            if (!Schema::hasColumn('bills', 'advance')) {
                $table->decimal('advance', 10, 2)->default(0.00)->after('previous_dues');
            }
            if (!Schema::hasColumn('bills', 'adjustment')) {
                $table->decimal('adjustment', 10, 2)->default(0.00)->after('advance');
            }
            if (!Schema::hasColumn('bills', 'adjustment_type')) {
                $table->enum('adjustment_type', ['Debit', 'Credit'])->nullable()->after('adjustment');
            }
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table) {
            if (Schema::hasColumn('bills', 'adjustment_type')) {
                $table->dropColumn('adjustment_type');
            }
            if (Schema::hasColumn('bills', 'adjustment')) {
                $table->dropColumn('adjustment');
            }
        });
    }
};
