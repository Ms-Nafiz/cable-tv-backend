<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'dues')) {
                $table->decimal('dues', 10, 2)->default(0.00)->after('monthly_rent');
            }
            if (!Schema::hasColumn('customers', 'advance')) {
                $table->decimal('advance', 10, 2)->default(0.00)->after('dues');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'dues')) {
                $table->dropColumn('dues');
            }
            if (Schema::hasColumn('customers', 'advance')) {
                $table->dropColumn('advance');
            }
        });
    }
};
