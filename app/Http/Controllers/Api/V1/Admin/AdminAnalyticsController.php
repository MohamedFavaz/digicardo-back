<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    use ApiResponse;

    /**
     * Monthly user signup counts for the last N months (default 8).
     * Returns an array of { month: "2026-08", count: 42 }.
     */
    public function signupsMonthly(Request $request): JsonResponse
    {
        $months = min(24, max(1, (int) $request->query('months', 8)));

        $rows = User::query()
            ->select(
                DB::raw("DATE_FORMAT(created_at, '%Y-%m') as month"),
                DB::raw('COUNT(*) as count')
            )
            ->where('created_at', '>=', now()->subMonths($months)->startOfMonth())
            ->groupBy('month')
            ->orderBy('month', 'asc')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month,
                'count' => (int) $row->count,
            ]);

        return $this->successResponse(['signups' => $rows]);
    }
}
