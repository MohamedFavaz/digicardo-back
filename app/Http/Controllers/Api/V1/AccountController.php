<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Account\ChangePasswordRequest;
use App\Http\Requests\Account\ConfirmPasswordRequest;
use App\Http\Requests\Account\DeleteAccountRequest;
use App\Http\Requests\Account\UpdateAccountRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Services\AccountSecurityService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AccountSecurityService $securityService
    ) {}

    /**
     * Get authenticated user's account details.
     */
    public function show(Request $request): JsonResponse
    {
        return $this->successResponse(
            new AccountResource($request->user())
        );
    }

    /**
     * Update authenticated user's account details (name and/or email).
     */
    public function update(UpdateAccountRequest $request): JsonResponse
    {
        $user = $this->securityService->updateAccount(
            $request->user(),
            $request->validated(),
            $request
        );

        return $this->successResponse(
            new AccountResource($user),
            ['message' => 'Account details updated successfully.']
        );
    }

    /**
     * Change user password, revoke other active sessions, and regenerate current session.
     */
    public function changePassword(ChangePasswordRequest $request): JsonResponse
    {
        $this->securityService->changePassword(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('password'),
            $request
        );

        return $this->successResponse(
            ['changed' => true],
            ['message' => 'Password updated successfully. All other sessions have been logged out.']
        );
    }

    /**
     * Confirm password for recent authentication step-up.
     */
    public function confirmPassword(ConfirmPasswordRequest $request): JsonResponse
    {
        $this->securityService->confirmRecentAuth(
            $request->user(),
            $request->validated('password'),
            $request
        );

        return $this->successResponse(
            ['confirmed' => true],
            ['message' => 'Security confirmation successful.']
        );
    }

    /**
     * Permanently delete user account and all associated data.
     */
    public function destroy(DeleteAccountRequest $request): JsonResponse
    {
        $this->securityService->deleteAccount(
            $request->user(),
            $request->validated('current_password'),
            $request->validated('confirmation'),
            $request
        );

        return $this->successResponse(
            null,
            ['message' => 'Account deleted successfully.']
        );
    }
}
