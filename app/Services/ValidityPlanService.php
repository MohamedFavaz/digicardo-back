<?php

namespace App\Services;

use App\Models\ValidityPlan;
use Illuminate\Database\Eloquent\Collection;

class ValidityPlanService
{
    /** Auto-seed default validity plans if table is empty. */
    public function ensureDefaults(): void
    {
        if (ValidityPlan::count() > 0) {
            return;
        }

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
            ValidityPlan::create([
                'id'              => (string) \Illuminate\Support\Str::ulid(),
                'name'            => $plan['name'],
                'months'          => $plan['months'],
                'price'           => $plan['price'],
                'currency_symbol' => '₹',
                'is_active'       => true,
                'sort_order'      => $plan['sort_order'],
            ]);
        }
    }

    /** Return all active plans ordered for display. */
    public function listActive(): Collection
    {
        $this->ensureDefaults();

        return ValidityPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /** Return all plans (including inactive) for admin settings. */
    public function listAll(): Collection
    {
        $this->ensureDefaults();

        return ValidityPlan::orderBy('sort_order')->get();
    }

    /** Create a new validity plan. */
    public function createPlan(array $data): ValidityPlan
    {
        $maxOrder = (int) ValidityPlan::max('sort_order');

        return ValidityPlan::create([
            'id'              => (string) \Illuminate\Support\Str::ulid(),
            'name'            => $data['name'],
            'months'          => (int) $data['months'],
            'price'           => (float) $data['price'],
            'currency_symbol' => $data['currency_symbol'] ?? '₹',
            'is_active'       => $data['is_active'] ?? true,
            'sort_order'      => $data['sort_order'] ?? ($maxOrder + 1),
        ]);
    }

    /** Update price and currency for a plan. */
    public function updatePrice(string $planId, float $price, string $symbol = '₹'): ValidityPlan
    {
        $plan = ValidityPlan::findOrFail($planId);
        $plan->update(['price' => $price, 'currency_symbol' => $symbol]);
        return $plan->fresh();
    }

    /** Toggle a plan active/inactive. */
    public function toggleActive(string $planId, bool $active): ValidityPlan
    {
        $plan = ValidityPlan::findOrFail($planId);
        $plan->update(['is_active' => $active]);
        return $plan->fresh();
    }

    /** Delete a validity plan. */
    public function deletePlan(string $planId): bool
    {
        $plan = ValidityPlan::findOrFail($planId);
        return (bool) $plan->delete();
    }

    /** Find a plan by ID. */
    public function find(string $planId): ValidityPlan
    {
        return ValidityPlan::findOrFail($planId);
    }
}
