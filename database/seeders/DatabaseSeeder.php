<?php

namespace Database\Seeders;

use App\Models\Area;
use App\Models\Customer;
use App\Models\Deposit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Roles
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin']);
        $accountsRole   = Role::firstOrCreate(['name' => 'accounts']);
        $collectorRole  = Role::firstOrCreate(['name' => 'collector']);

        // 2. Create Areas / Zones
        $areas = [
            Area::create(['name' => 'Dhanmondi Zone']),
            Area::create(['name' => 'Gulshan Zone']),
            Area::create(['name' => 'Uttara Zone']),
            Area::create(['name' => 'Mirpur Zone']),
        ];

        // 3. Create Admin & Accounts Users
        $admin = User::create([
            'name'     => 'Super Admin',
            'phone'    => '01700000000',
            'email'    => 'admin@cabletv.com',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $admin->assignRole($superAdminRole);

        $accounts = User::create([
            'name'     => 'Accounts Manager',
            'phone'    => '01800000000',
            'email'    => 'accounts@cabletv.com',
            'password' => Hash::make('password'),
            'status'   => 'active',
        ]);
        $accounts->assignRole($accountsRole);

        // 4. Create Collectors (1 per zone)
        $collector1 = User::create([
            'name'     => 'Dhanmondi Collector',
            'phone'    => '01711111111',
            'email'    => 'collector1@cabletv.com',
            'password' => Hash::make('password'),
            'area_id'  => $areas[0]->id,
            'status'   => 'active',
        ]);
        $collector1->assignRole($collectorRole);

        $collector2 = User::create([
            'name'     => 'Gulshan Collector',
            'phone'    => '01722222222',
            'email'    => 'collector2@cabletv.com',
            'password' => Hash::make('password'),
            'area_id'  => $areas[1]->id,
            'status'   => 'active',
        ]);
        $collector2->assignRole($collectorRole);

        $collector3 = User::create([
            'name'     => 'Uttara Collector',
            'phone'    => '01733333333',
            'email'    => 'collector3@cabletv.com',
            'password' => Hash::make('password'),
            'area_id'  => $areas[2]->id,
            'status'   => 'active',
        ]);
        $collector3->assignRole($collectorRole);

        $collector4 = User::create([
            'name'     => 'Mirpur Collector',
            'phone'    => '01744444444',
            'email'    => 'collector4@cabletv.com',
            'password' => Hash::make('password'),
            'area_id'  => $areas[3]->id,
            'status'   => 'active',
        ]);
        $collector4->assignRole($collectorRole);

        $collectors = [$collector1, $collector2, $collector3, $collector4];

        // 5. Seed 50 Fresh Clean Customers
        $firstNames = ['Md. Rahim', 'Md. Karim', 'Sharmin', 'Tariqul', 'Farhana', 'Sajid', 'Tanvir', 'Shirin', 'Habibur', 'Rashed', 'Mahmud', 'Nusrat', 'Kazi Anis', 'Kamrul', 'Selina', 'Imtiaz', 'Zubair', 'Fahmida', 'Ariful', 'Nasrin'];
        $lastNames  = ['Uddin', 'Chowdhury', 'Jahan', 'Islam', 'Akter', 'Khan', 'Ahmed', 'Sultana', 'Rahman', 'Hossain', 'Hasan', 'Alam', 'Begum', 'Sarker', 'Bhuiyan', 'Miah', 'Kazi', 'Khandakar', 'Pavel', 'Rana'];
        $streets    = ['Road 2, House ', 'Road 5, Block B, Flat ', 'Avenue 4, House ', 'Sector 3, Road 7, House ', 'Block C, House ', 'Section 10, Block D, House '];
        $phonePrefixes = ['017', '018', '019', '013', '014', '015', '016'];

        for ($i = 1; $i <= 50; $i++) {
            $customerCode = 'CCL' . str_pad($i, 5, '0', STR_PAD_LEFT);
            $fn = $firstNames[($i - 1) % count($firstNames)];
            $ln = $lastNames[($i * 3) % count($lastNames)];
            $name = "{$fn} {$ln}";

            $prefix = $phonePrefixes[$i % count($phonePrefixes)];
            $phone  = $prefix . str_pad($i * 123456 % 100000000, 8, '0', STR_PAD_LEFT);

            $areaIndex = ($i - 1) % count($areas);
            $area = $areas[$areaIndex];
            $assignedCollector = $collectors[$areaIndex];

            $street = $streets[($i - 1) % count($streets)];
            $address = "{$street}" . (($i % 40) + 1) . ", {$area->name}, Dhaka";

            $isDigital = ($i % 2 === 0);
            $connType  = $isDigital ? 'digital' : 'analog';
            $stbSerial = $isDigital ? 'STB-' . (8800000 + $i) : null;
            $monthlyRent = $isDigital ? 800.00 : 500.00;
            $depositAmount = $isDigital ? 1000.00 : 500.00;

            $connDate = date('Y-m-d', strtotime("-{$i} days"));

            $customer = Customer::create([
                'customer_code'         => $customerCode,
                'name'                  => $name,
                'phone'                 => $phone,
                'address'               => $address,
                'area_id'               => $area->id,
                'connection_type'       => $connType,
                'stb_serial'            => $stbSerial,
                'monthly_rent'          => $monthlyRent,
                'deposit_amount'        => $depositAmount,
                'advance_balance'       => 0.00,
                'connection_date'       => $connDate,
                'status'                => 'active',
                'assigned_collector_id' => $assignedCollector->id,
            ]);

            // Initial Security Deposit Entry
            Deposit::create([
                'customer_id' => $customer->id,
                'amount'      => $depositAmount,
                'type'        => 'collected',
                'date'        => $connDate,
                'remarks'     => 'Initial Security Deposit (' . ucfirst($connType) . ')',
                'created_by'  => $admin->id,
            ]);
        }
    }
}
