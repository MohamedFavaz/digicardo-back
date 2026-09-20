<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Plan
 */
class PlanResource extends JsonResource
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
            'code' => $this->code,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'price_monthly_cents' => (int) $this->price_monthly_cents,
            'price_yearly_cents' => (int) $this->price_yearly_cents,
            'currency' => $this->currency ?? 'USD',
            'features' => $this->features ?? [],
            'limits' => $this->limits ?? [],
            'is_active' => (bool) $this->is_active,
            'sort_order' => (int) $this->sort_order,
        ];
    }
}
