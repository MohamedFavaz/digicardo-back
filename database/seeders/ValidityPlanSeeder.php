<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ValidityPlanSeeder extends Seeder
{
    public function run(): void
    {
        $plans = [
            ['months' => 1,  'name' => '1 Month',  'price' => 99.00,   'sort_order' => 1],
            ['months' => 3,  'name' => '3 Months', 'price' => 249.00,  'sort_order' => 2],
            ['months' => 6,  'name' => '6 Months', 'price' => 449.00,  'sort_order' => 3],
            ['months' => 12, 'name' => '1 Year',   'price' => 799.00,  'sort_order' => 4],
            ['months' => 24, 'name' => '2 Years',  'price' => 1399.00, 'sort_order' => 5],
            ['months' => 36, 'name' => '3 Years',  'price' => 1899.00, 'sort_order' => 6],
            ['months' => 60, 'name' => '5 Years',  'price' => 2999.00, 'sort_order' => 7],
        ];

        foreach ($plans as $plan) {
            DB::table('validity_plans')->updateOrInsert(
                ['months' => $plan['months']],
                [
                    'id'              => (string) Str::ulid(),
                    'name'            => $plan['name'],
                    'months'          => $plan['months'],
                    'price'           => $plan['price'],
                    'currency_symbol' => '₹',
                    'is_active'       => true,
                    'sort_order'      => $plan['sort_order'],
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ]
            );
        }
    }
}
