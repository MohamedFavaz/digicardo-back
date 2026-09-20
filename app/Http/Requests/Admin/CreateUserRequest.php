<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Api\V1\BaseApiRequest;

class CreateUserRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name'             => ['required', 'string', 'max:100'],
            'email'            => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'username'         => ['required', 'string', 'min:3', 'max:30', 'regex:/^[a-zA-Z0-9_-]+$/', 'unique:profiles,username'],
            // Password is optional — auto-generated securely if omitted
            'password'         => ['sometimes', 'nullable', 'string', 'min:8', 'max:100'],
            'role'             => ['sometimes', 'string', 'in:user,moderator,admin'],
            'status'           => ['sometimes', 'string', 'in:active,suspended,banned'],
            // Validity plan links user to an expiry schedule
            'validity_plan_id' => ['sometimes', 'nullable', 'string', 'exists:validity_plans,id'],
        ];
    }
}
