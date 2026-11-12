<?php

namespace App\Services;

use App\Enums\AgentPricingModel;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\AgentCard;
use App\Models\AgentCommissionConfig;
use App\Models\AgentPaymentRecord;
use App\Models\AgentQuota;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class AgentService
{
    /**
     * List all agents with pagination, search, and filtering.
     *
     * @param array{search?: string, status?: string, per_page?: int} $filters
     */
    public function listAgents(array $filters = []): LengthAwarePaginator
    {
        $query = User::where('role', UserRole::Agent)
            ->with(['agentQuota', 'commissionConfig']);

        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Create a new agent with quota and commission config.
     */
    public function createAgent(array $data): User
    {
        return DB::transaction(function () use ($data) {
            // Create user with agent role
            $agent = User::create([
                'name' => $data['name'],
                'email' => strtolower(trim($data['email'])),
                'mobile' => !empty($data['mobile']) ? trim($data['mobile']) : null,
                'password' => Hash::make($data['password']),
                'role' => UserRole::Agent,
                'status' => UserStatus::Active,
                'email_verified_at' => now(),
            ]);

            // Create agent quota
            AgentQuota::create([
                'agent_id' => $agent->id,
                'card_limit' => $data['card_limit'] ?? 0,
                'cards_used' => 0,
                'restore_on_delete' => $data['restore_on_delete'] ?? true,
            ]);

            // Create agent-specific commission config
            $cardPrice = $data['card_price'] ?? 500;
            $agentCommission = $data['agent_commission'] ?? 200;
            $adminProfit = $cardPrice - $agentCommission;

            AgentCommissionConfig::create([
                'agent_id' => $agent->id,
                'card_price' => $cardPrice,
                'agent_commission' => $agentCommission,
                'admin_profit' => max(0, $adminProfit),
                'pricing_model' => $data['pricing_model'] ?? AgentPricingModel::Fixed,
            ]);

            $agent->load(['agentQuota', 'commissionConfig']);

            return $agent;
        });
    }

    /**
     * Update an existing agent's details, quota, and/or commission config.
     */
    public function updateAgent(User $agent, array $data): User
    {
        return DB::transaction(function () use ($agent, $data) {
            // Update user fields
            $userFields = [];
            foreach (['name', 'email', 'mobile'] as $field) {
                if (isset($data[$field])) {
                    $userFields[$field] = $data[$field];
                }
            }
            if (isset($data['status'])) {
                $userFields['status'] = UserStatus::from($data['status']);
            }
            if (!empty($data['password'])) {
                $userFields['password'] = Hash::make($data['password']);
            }
            if (!empty($userFields)) {
                $agent->update($userFields);
            }

            // Update quota if provided
            if (isset($data['card_limit']) || isset($data['restore_on_delete'])) {
                $quota = $agent->agentQuota;
                if (!$quota) {
                    $quota = new AgentQuota(['agent_id' => $agent->id]);
                }
                if (isset($data['card_limit'])) {
                    $quota->card_limit = $data['card_limit'];
                }
                if (isset($data['restore_on_delete'])) {
                    $quota->restore_on_delete = $data['restore_on_delete'];
                }
                $quota->save();
            }

            // Update commission config if provided
            if (isset($data['card_price']) || isset($data['agent_commission'])) {
                $config = $agent->commissionConfig;
                if (!$config) {
                    $config = new AgentCommissionConfig(['agent_id' => $agent->id]);
                }
                if (isset($data['card_price'])) {
                    $config->card_price = $data['card_price'];
                }
                if (isset($data['agent_commission'])) {
                    $config->agent_commission = $data['agent_commission'];
                }
                $config->admin_profit = $config->card_price - $config->agent_commission;
                if (isset($data['pricing_model'])) {
                    $config->pricing_model = $data['pricing_model'];
                }
                $config->save();
            }

            $agent->load(['agentQuota', 'commissionConfig']);

            return $agent;
        });
    }

    /**
     * Activate an agent account.
     */
    public function activateAgent(User $agent): User
    {
        $agent->update(['status' => UserStatus::Active]);

        return $agent->fresh(['agentQuota', 'commissionConfig']);
    }

    /**
     * Deactivate (suspend) an agent account.
     */
    public function deactivateAgent(User $agent): User
    {
        $agent->update(['status' => UserStatus::Suspended]);

        return $agent->fresh(['agentQuota', 'commissionConfig']);
    }

    /**
     * Soft-delete an agent and cascade to related records.
     * Application-managed cascade — not relying on MySQL FK CASCADE.
     */
    public function deleteAgent(User $agent): void
    {
        DB::transaction(function () use ($agent) {
            // Soft-delete agent's cards
            $agent->agentCards()->each(function (AgentCard $card) {
                $card->delete();
            });

            // Soft-delete quota and commission config
            $agent->agentQuota?->delete();
            $agent->commissionConfig?->delete();

            // Soft-delete transactions and payments
            $agent->agentTransactions()->delete();
            $agent->agentPayments()->delete();

            // Suffix email upon soft delete so the original email is immediately freed for re-creation
            $originalEmail = $agent->email;
            $agent->email = 'deleted_' . time() . '_' . $originalEmail;
            $agent->save();

            // Soft-delete the agent user
            $agent->delete();
        });
    }

    /**
     * Get detailed performance stats for a single agent.
     */
    public function getAgentPerformance(User $agent): array
    {
        $cards = $agent->agentCards();

        return [
            'total_cards' => $cards->count(),
            'active_cards' => (clone $cards)->where('status', 'active')->count(),
            'inactive_cards' => (clone $cards)->where('status', 'inactive')->count(),
            'total_revenue' => (float) (clone $cards)->sum('revenue'),
            'total_commission' => (float) (clone $cards)->sum('agent_commission'),
            'total_profit' => (float) (clone $cards)->sum('admin_profit'),
            'quota' => $agent->agentQuota,
            'commission_config' => $agent->commissionConfig,
        ];
    }

    /**
     * Get aggregated stats for the admin agent dashboard.
     */
    public function getAdminAgentDashboard(): array
    {
        $totalAgents = User::where('role', UserRole::Agent)->count();
        $activeAgents = User::where('role', UserRole::Agent)
            ->where('status', UserStatus::Active)
            ->count();

        $totalCards = AgentCard::count();
        $totalRevenue = (float) AgentCard::sum('revenue');
        $totalProfit = (float) AgentCard::sum('admin_profit');
        $totalCommission = (float) AgentCard::sum('agent_commission');

        // Pending payments = total commission earned − total commission paid out
        $paidCommission = (float) AgentPaymentRecord::where('status', 'paid')->sum('amount');

        return [
            'total_agents' => $totalAgents,
            'active_agents' => $activeAgents,
            'total_agent_cards' => $totalCards,
            'total_revenue' => $totalRevenue,
            'total_profit' => $totalProfit,
            'total_commission' => $totalCommission,
            'pending_payments' => max(0, $totalCommission - $paidCommission),
        ];
    }

    /**
     * Get agent performance table data for admin dashboard.
     *
     * @return array<int, array{agent: User, cards_created: int, card_limit: int, revenue: float, profit: float}>
     */
    public function getAgentPerformanceTable(): array
    {
        $agents = User::where('role', UserRole::Agent)
            ->with(['agentQuota', 'commissionConfig'])
            ->withCount('agentCards')
            ->withSum('agentCards as revenue_sum', 'revenue')
            ->withSum('agentCards as profit_sum', 'admin_profit')
            ->withSum('agentCards as commission_sum', 'agent_commission')
            ->orderByDesc('agent_cards_count')
            ->get();

        return $agents->map(function (User $agent) {
            return [
                'agent' => $agent,
                'cards_created' => $agent->agent_cards_count ?? 0,
                'card_limit' => $agent->agentQuota?->card_limit ?? 0,
                'revenue' => (float) ($agent->revenue_sum ?? 0),
                'profit' => (float) ($agent->profit_sum ?? 0),
                'commission' => (float) ($agent->commission_sum ?? 0),
            ];
        })->toArray();
    }
}

