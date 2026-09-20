<?php

namespace App\Services;

use App\Models\ValidityPlan;
use Illuminate\Database\Eloquent\Collection;

class ValidityPlanService
{
    /** Return all active plans ordered for display. */
    public function listActive(): Collection
    {
        return ValidityPlan::where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }

    /** Return all plans (including inactive) for admin settings. */
    public function listAll(): Collection
    {
        return ValidityPlan::orderBy('sort_order')->get();
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

    /** Find a plan by ID. */
    public function find(string $planId): ValidityPlan
    {
        return ValidityPlan::findOrFail($planId);
    }
}
