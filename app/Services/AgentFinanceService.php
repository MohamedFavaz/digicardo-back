<?php

namespace App\Services;

use App\Enums\AgentPaymentStatus;
use App\Enums\AgentTransactionType;
use App\Models\AgentCard;
use App\Models\AgentPaymentRecord;
use App\Models\AgentTransaction;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class AgentFinanceService
{
    /**
     * Get the financial dashboard aggregates for admin.
     */
    public function getFinancialDashboard(): array
    {
        $totalRevenue = (float) AgentCard::sum('revenue');
        $totalProfit = (float) AgentCard::sum('admin_profit');
        $totalCommission = (float) AgentCard::sum('agent_commission');
        $paidCommission = (float) AgentPaymentRecord::where('status', 'paid')->sum('amount');
        $pendingPayments = max(0, $totalCommission - $paidCommission);
        $totalCardsSold = AgentCard::count();

        // Monthly revenue (last 12 months) — database-agnostic
        $driver = DB::connection()->getDriverName();
        $dateExpr = $driver === 'sqlite'
            ? "strftime('%Y-%m', created_at)"
            : "DATE_FORMAT(created_at, '%Y-%m')";

        $monthlyRevenue = AgentCard::selectRaw("{$dateExpr} as month, SUM(revenue) as revenue, SUM(admin_profit) as profit, SUM(agent_commission) as commission")
            ->where('created_at', '>=', now()->subMonths(12))
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->toArray();

        return [
            'total_revenue' => $totalRevenue,
            'total_profit' => $totalProfit,
            'total_commission' => $totalCommission,
            'paid_commission' => $paidCommission,
            'pending_payments' => $pendingPayments,
            'total_cards_sold' => $totalCardsSold,
            'monthly_breakdown' => $monthlyRevenue,
        ];
    }

    /**
     * Get revenue report with optional agent and date filters.
     */
    public function getRevenueReport(array $filters = []): LengthAwarePaginator
    {
        $query = AgentCard::with(['agent'])
            ->select('agent_id')
            ->selectRaw('COUNT(*) as total_cards')
            ->selectRaw('SUM(revenue) as total_revenue')
            ->selectRaw('SUM(admin_profit) as total_profit')
            ->selectRaw('SUM(agent_commission) as total_commission')
            ->groupBy('agent_id');

        if (!empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (!empty($filters['from_date'])) {
            $query->where('created_at', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->where('created_at', '<=', $filters['to_date']);
        }

        return $query->orderByDesc('total_revenue')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Get agent's earnings summary.
     */
    public function getAgentEarnings(User $agent): array
    {
        $totalCommission = (float) AgentCard::where('agent_id', $agent->id)->sum('agent_commission');
        $paidCommission = (float) AgentPaymentRecord::where('agent_id', $agent->id)
            ->where('status', 'paid')
            ->sum('amount');
        $pendingCommission = max(0, $totalCommission - $paidCommission);

        $totalRevenue = (float) AgentCard::where('agent_id', $agent->id)->sum('revenue');
        $totalCards = AgentCard::where('agent_id', $agent->id)->count();

        return [
            'total_revenue' => $totalRevenue,
            'total_commission' => $totalCommission,
            'paid_commission' => $paidCommission,
            'pending_commission' => $pendingCommission,
            'total_cards_sold' => $totalCards,
        ];
    }

    /**
     * Get transaction history for an agent.
     */
    public function getTransactions(User $agent, array $filters = []): LengthAwarePaginator
    {
        $query = AgentTransaction::where('agent_id', $agent->id)
            ->with(['agentCard']);

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }

    /**
     * Record a commission payment to an agent.
     */
    public function recordPayment(array $data): AgentPaymentRecord
    {
        return DB::transaction(function () use ($data) {
            $payment = AgentPaymentRecord::create([
                'agent_id' => $data['agent_id'],
                'amount' => $data['amount'],
                'status' => AgentPaymentStatus::from($data['status'] ?? 'paid'),
                'payment_method' => $data['payment_method'] ?? null,
                'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'paid_at' => $data['status'] === 'paid' ? now() : null,
            ]);

            // Record as transaction
            AgentTransaction::create([
                'agent_id' => $data['agent_id'],
                'agent_card_id' => null,
                'type' => AgentTransactionType::CommissionPayment,
                'amount' => $data['amount'],
                'description' => 'Commission payment: ' . ($data['reference_number'] ?? $payment->id),
            ]);

            return $payment;
        });
    }

    /**
     * Get payment history for admin or agent.
     */
    public function getPaymentHistory(array $filters = []): LengthAwarePaginator
    {
        $query = AgentPaymentRecord::with(['agent']);

        if (!empty($filters['agent_id'])) {
            $query->where('agent_id', $filters['agent_id']);
        }

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderBy('created_at', 'desc')
            ->paginate($filters['per_page'] ?? 15);
    }
}

