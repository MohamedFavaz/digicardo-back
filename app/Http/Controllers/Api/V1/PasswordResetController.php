<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Services\AuthEmailService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetController extends Controller
{
    public function __construct(
        protected AuthEmailService $authEmailService
    ) {}

    /**
     * Request password reset link (generic response to prevent user enumeration).
     */
    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $this->authEmailService->sendPasswordResetLink($request->validated('email'));

        return response()->json([
            'success' => true,
            'data' => [
                'queued' => true,
            ],
            'message' => 'If an account with that email exists, password reset instructions have been sent.',
        ]);
    }

    /**
     * Reset password using token.
     */
    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $success = $this->authEmailService->resetPassword(
            $validated['email'],
            $validated['token'],
            $validated['password']
        );

        if (!$success) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_OR_EXPIRED_RESET_TOKEN',
                    'message' => 'This password reset token is invalid or has expired. Please request a new one.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'reset' => true,
            ],
            'message' => 'Your password has been reset successfully. You may now log in with your new password.',
        ]);
    }
}
