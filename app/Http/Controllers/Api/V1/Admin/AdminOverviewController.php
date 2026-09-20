<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AbuseReport;
use App\Models\ModerationAction;
use App\Models\Profile;
use App\Models\User;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;

class AdminOverviewController extends Controller
{
    use ApiResponse;

    /**
     * Get platform administration and moderation overview statistics.
     */
    public function index(): JsonResponse
    {
        $totalUsers = User::count();
        $activeUsers = User::where('status', 'active')->count();
        $suspendedUsers = User::whereIn('status', ['suspended', 'banned'])->count();

        $totalProfiles = Profile::count();
        $underReviewProfiles = Profile::where('moderation_status', 'under_review')->count();
        $restrictedProfiles = Profile::whereIn('moderation_status', ['restricted', 'suspended'])->count();

        $openReports = AbuseReport::where('status', 'open')->count();
        $investigatingReports = AbuseReport::where('status', 'investigating')->count();

        $recentActions = ModerationAction::with('actor:id,name,email,role')
            ->orderBy('created_at', 'desc')
            ->take(5)
            ->get();

        return $this->successResponse([
            'metrics' => [
                'users' => [
                    'total' => $totalUsers,
                    'active' => $activeUsers,
                    'suspended' => $suspendedUsers,
                ],
                'profiles' => [
                    'total' => $totalProfiles,
                    'under_review' => $underReviewProfiles,
                    'restricted_or_suspended' => $restrictedProfiles,
                ],
                'reports' => [
                    'open' => $openReports,
                    'investigating' => $investigatingReports,
                    'total_pending' => $openReports + $investigatingReports,
                ],
            ],
            'recent_actions' => $recentActions,
        ]);
    }
}
