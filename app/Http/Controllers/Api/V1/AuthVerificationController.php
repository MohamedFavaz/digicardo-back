<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuthEmailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthVerificationController extends Controller
{
    public function __construct(
        protected AuthEmailService $authEmailService
    ) {}

    /**
     * Resend email verification notification.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'success' => true,
                'data' => ['already_verified' => true],
                'message' => 'Email address is already verified.',
            ]);
        }

        $this->authEmailService->sendVerificationNotification($user);

        return response()->json([
            'success' => true,
            'data' => ['sent' => true],
            'message' => 'Verification email notification has been queued.',
        ]);
    }

    /**
     * Verify email with signature.
     */
    public function verify(Request $request, string $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        $expires = (int) $request->query('expires', 0);
        $signature = (string) $request->query('signature', '');

        $verified = $this->authEmailService->verifyEmail($user, $hash, $expires, $signature);

        if (!$verified) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_OR_EXPIRED_VERIFICATION_LINK',
                    'message' => 'The verification link is invalid or has expired. Please request a new one.',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'verified' => true,
                'email' => $user->email,
            ],
            'message' => 'Email address successfully verified.',
        ]);
    }
}
