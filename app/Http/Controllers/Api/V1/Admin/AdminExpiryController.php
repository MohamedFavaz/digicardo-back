<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminUserService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminExpiryController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AdminUserService $adminUserService
    ) {}

    /** Users expiring in the next 7 days (warning alerts). */
    public function expiring(): JsonResponse
    {
        $users = $this->adminUserService->getExpiringUsers(7)->map(fn ($u) => [
            'id'             => $u->id,
            'name'           => $u->name,
            'email'          => $u->email,
            'username'       => $u->profile?->username,
            'expires_at'     => $u->expires_at?->toIso8601String(),
            'days_remaining' => max(0, now()->diffInDays($u->expires_at, false)),
            'validity_label' => $u->validity_label,
        ]);

        return $this->successResponse(['users' => $users, 'count' => $users->count()]);
    }

    /** Users whose validity has already expired. */
    public function expired(): JsonResponse
    {
        $users = $this->adminUserService->getExpiredUsers()->map(fn ($u) => [
            'id'             => $u->id,
            'name'           => $u->name,
            'email'          => $u->email,
            'username'       => $u->profile?->username,
            'expires_at'     => $u->expires_at?->toIso8601String(),
            'expired_days'   => abs(now()->diffInDays($u->expires_at, false)),
            'status'         => $u->status instanceof \App\Enums\UserStatus ? $u->status->value : (string) $u->status,
            'validity_label' => $u->validity_label,
        ]);

        return $this->successResponse(['users' => $users, 'count' => $users->count()]);
    }
}
