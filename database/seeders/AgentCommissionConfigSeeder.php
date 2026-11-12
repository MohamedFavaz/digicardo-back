<?php

namespace Database\Seeders;

use App\Models\AgentCommissionConfig;
use Illuminate\Database\Seeder;

class AgentCommissionConfigSeeder extends Seeder
{
    /**
     * Seed the global default agent commission configuration.
     * agent_id = null represents the system-wide default pricing.
     */
    public function run(): void
    {
        AgentCommissionConfig::firstOrCreate(
            ['agent_id' => null],
            [
                'card_price' => 500.00,
                'agent_commission' => 200.00,
                'admin_profit' => 300.00,
                'pricing_model' => 'fixed',
            ]
        );
    }
}

