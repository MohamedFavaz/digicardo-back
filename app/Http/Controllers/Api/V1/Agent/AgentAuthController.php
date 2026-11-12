<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AgentAuthController extends Controller
{
    /**
     * Authenticate an agent via email/password.
     * Rejects non-agent users attempting to use this portal.
     */
    public function login(Request $request): JsonResponse
    {
        $loginInput = strtolower(trim((string) $request->input('email')));
        $targetEmail = $loginInput;

        // Support login by email OR mobile
        if (!str_contains($loginInput, '@')) {
            $matchedAgent = \App\Models\User::where('role', 'agent')
                ->where('mobile', $loginInput)
                ->first();
            if ($matchedAgent) {
                $targetEmail = $matchedAgent->email;
            }
        }

        $validated = $request->validate([
            'email' => 'required|string',
            'password' => 'required|string|min:8',
        ]);

        if (!Auth::attempt(['email' => $targetEmail, 'password' => $validated['password']], $request->boolean('remember', true))) {
            // Inform clearly if this email is a customer instead of an agent
            $existingUser = \App\Models\User::where('email', $targetEmail)->first();
            if ($existingUser) {
                $role = $existingUser->role;
                $isAgent = ($role instanceof UserRole && $role->isAgent()) || $role === 'agent';
                if (!$isAgent) {
                    return response()->json([
                        'success' => false,
                        'error' => [
                            'code' => 'NOT_AN_AGENT_ACCOUNT',
                            'message' => 'This account is a customer account, not an agent account. Please log in at /login.',
                        ],
                    ], Response::HTTP_FORBIDDEN);
                }
            }

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_CREDENTIALS',
                    'message' => 'Invalid email or password.',
                ],
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = Auth::user();

        // Verify the user is an agent
        $role = $user->role;
        $isAgent = ($role instanceof UserRole && $role->isAgent()) || $role === 'agent';

        if (!$isAgent) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'INVALID_AGENT_CREDENTIALS',
                    'message' => 'This login portal is for authorized agents only.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        // Verify agent is active
        $status = $user->status;
        $isActive = ($status instanceof UserStatus && $status->isActive()) || $status === 'active';

        if (!$isActive) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'AGENT_ACCOUNT_SUSPENDED',
                    'message' => 'Your agent account has been suspended. Please contact the administrator.',
                ],
            ], Response::HTTP_FORBIDDEN);
        }

        $request->session()->regenerate();

        // Load agent-specific relationships
        $user->load(['agentQuota', 'commissionConfig']);

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'quota' => $user->agentQuota,
                'commission_config' => $user->commissionConfig,
            ],
            'message' => 'Agent login successful.',
        ]);
    }

    /**
     * Log out the current agent session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Return the currently authenticated agent with quota and commission data.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        // 1. If currently impersonating a customer, the real agent is in session
        if ($request->hasSession() && $request->session()->has('impersonator_agent_id')) {
            $agentId = $request->session()->get('impersonator_agent_id');
            $agent = \App\Models\User::find($agentId);
            if ($agent) {
                $agent->load(['agentQuota', 'commissionConfig']);
                $cardsCount = $agent->agentCards()->count();

                return response()->json([
                    'success' => true,
                    'data' => [
                        'user' => $agent,
                        'quota' => $agent->agentQuota,
                        'commission_config' => $agent->commissionConfig,
                        'total_cards' => $cardsCount,
                        'is_impersonating' => true,
                        'impersonated_customer' => $user,
                    ],
                ]);
            }
        }

        // 2. If an administrator is viewing or testing the agent panel
        $role = $user->role;
        $roleValue = $role instanceof UserRole ? $role->value : (is_string($role) ? $role : '');
        $isAdmin = ($role instanceof UserRole && $role->isAdmin()) || $roleValue === 'admin';
        if ($isAdmin) {
            $cardsCount = $user->agentCards()->count();
            return response()->json([
                'success' => true,
                'data' => [
                    'user' => $user,
                    'quota' => (object) [
                        'cards_used' => 0,
                        'card_limit' => 999999,
                    ],
                    'commission_config' => null,
                    'total_cards' => $cardsCount,
                    'is_admin' => true,
                    'is_impersonating' => false,
                ],
            ]);
        }

        // 3. Regular authenticated agent
        $user->load(['agentQuota', 'commissionConfig']);
        $cardsCount = $user->agentCards()->count();

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $user,
                'quota' => $user->agentQuota,
                'commission_config' => $user->commissionConfig,
                'total_cards' => $cardsCount,
                'is_impersonating' => false,
            ],
        ]);
    }
}

