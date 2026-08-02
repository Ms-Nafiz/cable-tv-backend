<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanDataCommand extends Command
{
    protected $signature = 'db:clean';
    protected $description = 'Clean all customer, bill, payment, and deposit data while keeping system users intact';

    public function handle()
    {
        if ($this->confirm('Are you sure you want to WIPE all customers, bills, payments, and deposits? System users will remain intact.', true)) {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
            DB::table('payments')->truncate();
            DB::table('bills')->truncate();
            DB::table('deposits')->truncate();
            DB::table('customers')->truncate();
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');

            $this->info('Database cleaned successfully! Users table remains intact.');
        } else {
            $this->info('Database cleaning cancelled.');
        }

        return 0;
    }
}
