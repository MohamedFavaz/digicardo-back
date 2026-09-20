<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Subscription
 */
class SubscriptionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_code' => $this->plan?->code,
            'plan' => $this->plan ? new PlanResource($this->plan) : null,
            'provider' => $this->provider,
            'status' => $this->status instanceof \App\Enums\SubscriptionStatus ? $this->status->value : (string) $this->status,
            'billing_interval' => $this->billing_interval,
            'current_period_start' => $this->current_period_start?->toIso8601String(),
            'current_period_end' => $this->current_period_end?->toIso8601String(),
            'cancel_at_period_end' => (bool) $this->cancel_at_period_end,
            'canceled_at' => $this->canceled_at?->toIso8601String(),
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'grace_period_ends_at' => $this->grace_period_ends_at?->toIso8601String(),
            'is_active' => $this->isActive(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
