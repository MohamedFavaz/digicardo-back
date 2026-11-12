<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
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
            'name' => $this->name,
            'email' => $this->email,
            'role' => $this->role instanceof \BackedEnum ? $this->role->value : (is_string($this->role) ? $this->role : 'user'),
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : (is_string($this->status) ? $this->status : 'active'),
            'email_verified_at' => $this->email_verified_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'is_impersonated' => $request->hasSession() && (
                (bool) $request->session()->get('impersonator_admin_id') ||
                (bool) $request->session()->get('impersonator_agent_id')
            ),
            'impersonator_admin_name' => $request->hasSession() ? (
                $request->session()->get('impersonator_admin_name') ?? $request->session()->get('impersonator_agent_name')
            ) : null,
            'impersonator_role' => $request->hasSession() ? (
                $request->session()->get('impersonator_role') ?? (
                    $request->session()->has('impersonator_admin_id') ? 'admin' : (
                        $request->session()->has('impersonator_agent_id') ? 'agent' : null
                    )
                )
            ) : null,
        ];
    }
}
