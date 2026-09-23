<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Services\AuthService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly AuthService $authService
    ) {
    }

    /**
     * Handle user registration.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        if (env('ALLOW_PUBLIC_REGISTRATION', true) === false) {
            return $this->errorResponse(
                'PUBLIC_REGISTRATION_DISABLED',
                'Public registration is disabled. Digicardo accounts are provisioned exclusively by platform administrators.',
                Response::HTTP_FORBIDDEN
            );
        }

        $user = $this->authService->register($request->validated());

        return $this->successResponse(
            new UserResource($user),
            ['message' => 'Registration successful.']
        );
    }

    /**
     * Handle user login.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authService->login($request->validated());

        return $this->successResponse(
            new UserResource($user),
            ['message' => 'Login successful.']
        );
    }

    /**
     * Handle user logout.
     */
    public function logout(Request $request): JsonResponse
    {
        $this->authService->logout();

        return $this->successResponse(
            ['message' => 'Logged out successfully.']
        );
    }

    /**
     * Retrieve currently authenticated user.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $this->authService->me();

        return $this->successResponse(
            new UserResource($user)
        );
    }

    /**
     * Handshake endpoint to initialize session and CSRF cookie.
     */
    public function csrf(Request $request): JsonResponse
    {
        return $this->successResponse([
            'csrf_token' => csrf_token(),
        ]);
    }

    /**
     * Terminate active admin impersonation session and restore administrator session.
     */
    public function stopImpersonation(Request $request): JsonResponse
    {
        $result = app(\App\Services\AdminUserService::class)->stopImpersonation();

        return $this->successResponse(
            [
                'admin' => new UserResource($result['admin']),
                'redirect_url' => $result['redirect_url'],
            ],
            ['message' => 'Impersonation ended. Administrator session restored.']
        );
    }
}
