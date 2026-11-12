<?php

namespace App\Services;

use App\Enums\AccountSecurityEventType;
use App\Enums\AgentCardStatus;
use App\Enums\AgentTransactionType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AccountSecurityEvent;
use App\Models\AgentCard;
use App\Models\AgentCommissionConfig;
use App\Models\AgentTransaction;
use App\Models\Profile;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AgentCardService
{
    /**
     * List cards belonging to an agent, with pagination, search, and filtering.
     *
     * @param array{search?: string, status?: string, per_page?: int} $filters
     */
    public function listCards(User $agent, array $filters = []): LengthAwarePaginator
    {
        $query = AgentCard::where('agent_id', $agent->id)
            ->with(['customerUser', 'profile']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('card_name', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * List ALL agent-created cards (admin view) with pagination.
     *
     * @param array{search?: string, status?: string, agent_id?: string, per_page?: int} $filters
     */
    public function listAllCards(array $filters = []): LengthAwarePaginator
    {
        $query = AgentCard::with(['agent', 'customerUser', 'profile']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('card_name', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Check if the agent has available card creation credits.
     */
    public function checkQuota(User $agent): bool
    {
        $quota = $agent->agentQuota;

        if (!$quota) {
            return false;
        }

        return $quota->hasAvailableCredits();
    }

    /**
     * Create a new digital card for a customer.
     *
     * This is the core agent workflow:
     * 1. Verify quota
     * 2. Create customer User (role=user, agent_id=agent)
     * 3. Create Profile for customer
     * 4. Create AgentCard record
     * 5. Increment quota usage
     * 6. Record financial transaction
     *
     * @throws \App\Exceptions\CardLimitExceededException
     */
    public function createCard(User $agent, array $data): AgentCard
    {
        $quota = $agent->agentQuota;

        if (!$quota || !$quota->hasAvailableCredits()) {
            throw new \RuntimeException(
                'Card creation limit reached. You have used all ' . ($quota?->card_limit ?? 0) . ' card credits. Contact admin for additional credits.'
            );
        }

        return DB::transaction(function () use ($agent, $data, $quota) {
            // 1. Create customer user account
            $customerUser = User::create([
                'name' => $data['customer_name'],
                'email' => $data['customer_email'],
                'mobile' => $data['customer_phone'] ?? null,
                'password' => Hash::make($data['password'] ?? Str::random(16)),
                'role' => UserRole::User,
                'status' => UserStatus::Active,
                'agent_id' => $agent->id,
            ]);

            // 2. Resolve template and default theme
            $templateId = $data['template_id'] ?? 'botanical';
            if (!in_array($templateId, ['botanical', 'vcard'], true)) {
                $templateId = 'botanical';
            }
            $defaultTheme = $templateId === 'vcard'
                ? TemplateService::APPROVED_TEMPLATES['vcard']['default_theme']
                : TemplateService::APPROVED_TEMPLATES['botanical']['default_theme'];
            $themeTokens = !empty($data['theme_tokens']) ? $data['theme_tokens'] : $defaultTheme;

            // Create profile (digital card)
            $profile = Profile::create([
                'user_id' => $customerUser->id,
                'username' => $data['username'],
                'display_name' => $data['display_name'] ?? $data['customer_name'],
                'bio' => $data['bio'] ?? null,
                'template_id' => $templateId,
                'theme_tokens' => $themeTokens,
                'is_public' => true,
            ]);

            // 3. Get commission config (agent-specific or global default)
            $config = $agent->commissionConfig
                ?? AgentCommissionConfig::whereNull('agent_id')->first();

            $revenue = $config?->card_price ?? 500;
            $commission = $config?->agent_commission ?? 200;
            $profit = $config?->admin_profit ?? ($revenue - $commission);

            // 4. Create agent card tracking record
            $card = AgentCard::create([
                'agent_id' => $agent->id,
                'customer_user_id' => $customerUser->id,
                'profile_id' => $profile->id,
                'card_name' => $data['card_name'] ?? $data['customer_name'],
                'customer_name' => $data['customer_name'],
                'customer_email' => $data['customer_email'],
                'customer_phone' => $data['customer_phone'] ?? null,
                'status' => AgentCardStatus::Active,
                'revenue' => $revenue,
                'agent_commission' => $commission,
                'admin_profit' => $profit,
                'notes' => $data['notes'] ?? null,
            ]);

            // 5. Increment quota usage (atomically)
            $quota->lockForUpdate();
            $quota->increment('cards_used');

            // 6. Record financial transaction
            AgentTransaction::create([
                'agent_id' => $agent->id,
                'agent_card_id' => $card->id,
                'type' => AgentTransactionType::CardSale,
                'amount' => $revenue,
                'description' => "Card created: {$card->card_name}",
            ]);

            $card->load(['customerUser', 'profile']);

            return $card;
        });
    }

    /**
     * Update an agent card's metadata.
     * Editing does NOT consume additional credits.
     */
    public function updateCard(AgentCard $card, array $data): AgentCard
    {
        $updateFields = [];
        foreach (['card_name', 'customer_name', 'customer_email', 'customer_phone', 'notes'] as $field) {
            if (isset($data[$field])) {
                $updateFields[$field] = $data[$field];
            }
        }

        if (isset($data['status'])) {
            $updateFields['status'] = AgentCardStatus::from($data['status']);
        }

        if (!empty($updateFields)) {
            $card->update($updateFields);
        }

        return $card->fresh(['customerUser', 'profile']);
    }

    /**
     * Delete (soft-delete) a card and optionally restore quota credit.
     */
    public function deleteCard(AgentCard $card): void
    {
        DB::transaction(function () use ($card) {
            $agent = User::find($card->agent_id);
            $quota = $agent?->agentQuota;

            // Restore credit if policy allows
            if ($quota && $quota->restore_on_delete && $quota->cards_used > 0) {
                $quota->decrement('cards_used');
            }

            // Soft-delete the card
            $card->delete();
        });
    }

    /**
     * Activate a card.
     */
    public function activateCard(AgentCard $card): AgentCard
    {
        $card->update(['status' => AgentCardStatus::Active]);

        // Also make the profile public
        if ($card->profile) {
            $card->profile->update(['is_public' => true]);
        }

        return $card->fresh(['customerUser', 'profile']);
    }

    /**
     * Deactivate (unpublish) a card.
     */
    public function deactivateCard(AgentCard $card): AgentCard
    {
        $card->update(['status' => AgentCardStatus::Inactive]);

        // Also make the profile private
        if ($card->profile) {
            $card->profile->update(['is_public' => false]);
        }

        return $card->fresh(['customerUser', 'profile']);
    }

    /**
     * Get agent dashboard statistics.
     */
    public function getAgentDashboard(User $agent): array
    {
        $quota = $agent->agentQuota;
        $cards = $agent->agentCards();

        $totalCards = $cards->count();
        $activeCards = (clone $cards)->where('status', 'active')->count();
        $totalRevenue = (float) (clone $cards)->sum('revenue');
        $totalCommission = (float) (clone $cards)->sum('agent_commission');

        // Monthly card creation (last 12 months) — database-agnostic
        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $monthlyCards = AgentCard::where('agent_id', $agent->id)
            ->selectRaw("{$dateExpr} as month, COUNT(*) as count")
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('count', 'month')
            ->toArray();

        // Monthly revenue (last 12 months)
        $monthlyRevenue = AgentCard::where('agent_id', $agent->id)
            ->selectRaw("{$dateExpr} as month, SUM(revenue) as amount")
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->pluck('amount', 'month')
            ->toArray();

        // Recent cards
        $recentCards = AgentCard::where('agent_id', $agent->id)
            ->with(['customerUser', 'profile'])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return [
            'total_cards' => $totalCards,
            'active_cards' => $activeCards,
            'card_limit' => $quota?->card_limit ?? 0,
            'cards_used' => $quota?->cards_used ?? 0,
            'remaining_credits' => $quota?->remainingCredits() ?? 0,
            'total_revenue' => $totalRevenue,
            'total_commission' => $totalCommission,
            'monthly_cards' => $monthlyCards,
            'monthly_revenue' => $monthlyRevenue,
            'recent_cards' => $recentCards,
        ];
    }

    /**
     * Impersonate a customer user associated with an agent's card.
     * Strictly restricted to the agent owning the specific card.
     *
     * @param User $agent The authenticated agent.
     * @param string $cardId The agent card ID.
     * @return array{user: User, card: AgentCard, redirect_url: string}
     */
    public function impersonateCustomer(User $agent, string $cardId): array
    {
        // 1. Verify caller has agent role
        $roleValue = $agent->role instanceof UserRole ? $agent->role->value : (is_string($agent->role) ? $agent->role : '');
        $isAgent = ($agent->role instanceof UserRole && $agent->role->isAgent())
            || $roleValue === 'agent';

        if (!$isAgent) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                'Unauthorized. Agent privileges are required to impersonate customer accounts.'
            );
        }

        // 2. Find card strictly belonging to this agent
        $card = AgentCard::where('agent_id', $agent->id)->findOrFail($cardId);

        // 3. Find customer user
        $targetUser = User::with('profile')->findOrFail($card->customer_user_id);

        // 4. Security checks:
        // Cannot impersonate admins or other agents
        $isTargetAdmin = ($targetUser->role instanceof UserRole && $targetUser->role->isAdmin())
            || $targetUser->role === 'admin';
        $isTargetAgent = ($targetUser->role instanceof UserRole && $targetUser->role->isAgent())
            || $targetUser->role === 'agent';

        if ($isTargetAdmin || $isTargetAgent) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                'Security violation. Agents cannot impersonate administrative or agent accounts.'
            );
        }

        // Target cannot be self
        if ($agent->id === $targetUser->id) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                'Cannot impersonate your own account.'
            );
        }

        // Verify customer user is actually associated with this agent
        if ($targetUser->agent_id !== null && $targetUser->agent_id !== $agent->id) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                'Unauthorized. You can only impersonate customers of your own cards.'
            );
        }

        // Verify customer account is active
        $isTargetActive = ($targetUser->status instanceof UserStatus && $targetUser->status->isActive())
            || $targetUser->status === 'active';

        if (!$isTargetActive) {
            throw new \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException(
                'Customer account is not active.'
            );
        }

        $request = request();

        // 5. Log target user into web session
        \Illuminate\Support\Facades\Auth::guard('web')->login($targetUser);

        // 6. Regenerate session to prevent fixation and attach impersonation markers
        if ($request->hasSession()) {
            $request->session()->regenerate();
            $request->session()->put('impersonator_agent_id', $agent->id);
            $request->session()->put('impersonator_agent_name', $agent->name);
            $request->session()->put('impersonator_agent_email', $agent->email);
            $request->session()->put('impersonator_role', 'agent');
            $request->session()->put('impersonated_at', now()->toIso8601String());
            $request->session()->save();

            try {
                if (app()->bound(\App\Services\SessionService::class)) {
                    app(\App\Services\SessionService::class)->registerSession($targetUser, $request);
                }
            } catch (\Throwable $e) {
            }
        }

        // 7. Record security audit event
        try {
            AccountSecurityEvent::create([
                'user_id' => $targetUser->id,
                'event_type' => AccountSecurityEventType::LoginSuccess,
                'metadata' => [
                    'action' => 'agent_impersonate',
                    'agent_id' => $agent->id,
                    'agent_email' => $agent->email,
                    'card_id' => $card->id,
                ],
                'ip_hash' => hash_hmac('sha256', (string) $request->ip(), config('app.key', 'Digicardo-salt')),
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
        }

        return [
            'user' => $targetUser,
            'card' => $card,
            'redirect_url' => '/dashboard',
        ];
    }
}

