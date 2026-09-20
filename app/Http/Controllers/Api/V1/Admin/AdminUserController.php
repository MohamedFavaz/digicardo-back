<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateUserRoleRequest;
use App\Http\Requests\Admin\UpdateUserStatusRequest;
use App\Services\AdminUserService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AdminUserService $adminUserService
    ) {}

    /**
     * List paginated users with search and filtering.
     */
    public function index(Request $request): JsonResponse
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(5, (int) $request->query('per_page', 15)));
        $sortBy = (string) $request->query('sort_by', 'created_at');
        $sortDir = (string) $request->query('sort_dir', 'desc');

        $filters = [
            'search' => $request->query('search'),
            'role' => $request->query('role'),
            'status' => $request->query('status'),
            'email_verified' => $request->query('email_verified'),
        ];

        $paginator = $this->adminUserService->listUsers($filters, $page, $perPage, $sortBy, $sortDir);

        return $this->successResponse([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
            ],
        ]);
    }

    /**
     * Get user details for admin inspection.
     */
    public function show(string $id): JsonResponse
    {
        $data = $this->adminUserService->getUserDetails($id);

        return $this->successResponse($data);
    }

    /**
     * Update user account status (active, suspended, banned).
     */
    public function updateStatus(UpdateUserStatusRequest $request, string $id): JsonResponse
    {
        $user = $this->adminUserService->updateStatus(
            $request->user(),
            $id,
            $request->validated('status'),
            $request->validated('reason')
        );

        $statusValue = $user->status instanceof \App\Enums\UserStatus ? $user->status->value : (string) $user->status;

        return $this->successResponse(
            ['user_id' => $user->id, 'status' => $statusValue],
            ['message' => "User status updated to {$statusValue}."]
        );
    }

    /**
     * Update user role (user, moderator, admin).
     */
    public function updateRole(UpdateUserRoleRequest $request, string $id): JsonResponse
    {
        $user = $this->adminUserService->updateRole(
            $request->user(),
            $id,
            $request->validated('role'),
            $request->validated('reason')
        );

        $roleValue = $user->role instanceof \App\Enums\UserRole ? $user->role->value : (string) $user->role;

        return $this->successResponse(
            ['user_id' => $user->id, 'role' => $roleValue],
            ['message' => "User role updated to {$roleValue}."]
        );
    }

    /**
     * Create a new user account with assigned username and profile.
     */
    public function store(\App\Http\Requests\Admin\CreateUserRequest $request): JsonResponse
    {
        $result = $this->adminUserService->createUser(
            $request->user(),
            $request->validated()
        );

        $user = $result['user'];

        return $this->successResponse(
            [
                'user' => [
                    'id'             => $user->id,
                    'name'           => $user->name,
                    'email'          => $user->email,
                    'role'           => $user->role instanceof \App\Enums\UserRole ? $user->role->value : (string) $user->role,
                    'status'         => $user->status instanceof \App\Enums\UserStatus ? $user->status->value : (string) $user->status,
                    'username'       => $user->profile?->username,
                    'expires_at'     => $user->expires_at?->toIso8601String(),
                    'validity_label' => $user->validity_label,
                    'plan_price_paid'=> $user->plan_price_paid ? (float) $user->plan_price_paid : null,
                ],
                // Shown ONCE — the plaintext credentials generated at creation (HTTPS only)
                'credentials' => [
                    'email'          => $user->email,
                    'username'       => $user->profile?->username,
                    'plain_password' => $result['plain_password'],
                ],
            ],
            ['message' => 'User account created and profile provisioned successfully.'],
            \Symfony\Component\HttpFoundation\Response::HTTP_CREATED
        );
    }

    /**
     * Renew a user's validity with a new plan.
     */
    public function renew(Request $request, string $id): JsonResponse
    {
        $request->validate(['validity_plan_id' => 'required|string|size:26']);

        $result = $this->adminUserService->renewUser(
            $request->user(),
            $id,
            $request->input('validity_plan_id')
        );

        return $this->successResponse([
            'user_id'    => $result['user']->id,
            'expires_at' => $result['expires_at'],
        ], ['message' => 'User validity renewed successfully.']);
    }

    /**
     * Permanently delete a user account, their profile link, and all associated data.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->adminUserService->deleteUser($request->user(), $id);

        return $this->successResponse(
            ['user_id' => $id],
            ['message' => 'User account and profile link permanently deleted from database and web.']
        );
    }

    /**
     * Direct impersonation into user's studio account without requiring credentials.
     * Strictly restricted to active platform administrators.
     */
    public function impersonate(Request $request, string $id): JsonResponse
    {
        $result = $this->adminUserService->impersonateUser($request->user(), $id);

        return $this->successResponse(
            [
                'user' => new \App\Http\Resources\Api\V1\UserResource($result['user']),
                'redirect_url' => $result['redirect_url'],
            ],
            ['message' => "Successfully authenticated as {$result['user']->name}."]
        );
    }
}
