<?php

use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ContactSubmissionController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InternalDomainResolutionController;
use App\Http\Controllers\Api\V1\MediaController;
use App\Http\Controllers\Api\V1\ProfileAnalyticsController;
use App\Http\Controllers\Api\V1\ProfileBlockController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\ProfileDomainController;
use App\Http\Controllers\Api\V1\PublicProfileController;
use App\Http\Controllers\Api\V1\TemplateController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes — Version 1
|--------------------------------------------------------------------------
|
| All Digicardo API endpoints are versioned under /api/v1/*
| Standard response format: { success, data, meta } or { success, error }
|
*/

Route::prefix('v1')->group(function () {
    // Operational Health Check (Public, Non-blocking)
    Route::get('/health', [HealthController::class, 'check'])->name('api.v1.health');

    // Public Templates Catalog
    Route::get('/templates', [TemplateController::class, 'index'])->name('api.v1.templates.index');

    // Public Profile Rendering Endpoint (Unauthenticated, Edge-cacheable)
    Route::get('/p/{username}', [PublicProfileController::class, 'show'])
        ->name('api.v1.public.profile');
    Route::get('/profiles/{username}', [PublicProfileController::class, 'show']);
    Route::get('/public/profiles/{username}', [PublicProfileController::class, 'show']);

    // Public Real-time Profile Stats Endpoint
    Route::get('/p/{username}/stats', [PublicProfileController::class, 'stats'])
        ->name('api.v1.public.profile.stats');
    Route::get('/public/profiles/{username}/stats', [PublicProfileController::class, 'stats']);

    // Public Contact Form Submission Endpoint
    Route::post('/public/profiles/{username}/contact', [ContactSubmissionController::class, 'submit'])
        ->name('api.v1.public.contact.submit');
    Route::post('/p/{username}/contact', [ContactSubmissionController::class, 'submit']);

    // Public Abuse Report Endpoint
    Route::post('/public/profiles/{username}/report', [\App\Http\Controllers\Api\V1\PublicReportController::class, 'submit'])
        ->middleware('throttle:10,1')
        ->name('api.v1.public.report.submit');
    Route::post('/p/{username}/report', [\App\Http\Controllers\Api\V1\PublicReportController::class, 'submit'])
        ->middleware('throttle:10,1');

    // Public Analytics Event Ingestion Endpoint (Lightweight Beacon)
    Route::post('/analytics/events', [AnalyticsController::class, 'ingest'])
        ->name('api.v1.analytics.events');

    // Public Access Request Submission (landing page "Request Access" form)
    Route::post('/access-requests', [\App\Http\Controllers\Api\V1\AccessRequestController::class, 'submit'])
        ->middleware('throttle:10,5')
        ->name('api.v1.access-requests.submit');

    // Authentication Routes
    Route::prefix('auth')->group(function () {
        // CSRF Token & Session Handshake
        Route::get('/csrf', [AuthController::class, 'csrf'])->name('api.v1.auth.csrf');

        // Public Rate-Limited Auth Endpoints
        Route::post('/register', [AuthController::class, 'register'])
            ->middleware('throttle:register')
            ->name('api.v1.auth.register');

        Route::post('/login', [AuthController::class, 'login'])
            ->middleware('throttle:login')
            ->name('api.v1.auth.login');

        // Password Reset Endpoints
        Route::post('/forgot-password', [\App\Http\Controllers\Api\V1\PasswordResetController::class, 'forgotPassword'])
            ->middleware('throttle:password-reset')
            ->name('api.v1.auth.forgot-password');

        Route::post('/reset-password', [\App\Http\Controllers\Api\V1\PasswordResetController::class, 'resetPassword'])
            ->middleware('throttle:password-reset')
            ->name('api.v1.auth.reset-password');

        // Public Signed Verification Endpoint
        Route::get('/email/verify/{id}/{hash}', [\App\Http\Controllers\Api\V1\AuthVerificationController::class, 'verify'])
            ->name('verification.verify');

        // Protected Auth Endpoints (Requires Valid Authenticated Session)
        Route::middleware('auth:sanctum')->group(function () {
            Route::get('/me', [AuthController::class, 'me'])->name('api.v1.auth.me');
            Route::post('/logout', [AuthController::class, 'logout'])->name('api.v1.auth.logout');
            Route::post('/stop-impersonation', [AuthController::class, 'stopImpersonation'])->name('api.v1.auth.stop-impersonation');
            Route::post('/email/verification-notification', [\App\Http\Controllers\Api\V1\AuthVerificationController::class, 'send'])
                ->middleware('throttle:verification-notification')
                ->name('verification.send');
        });
    });

    // Authenticated Profile Management Routes
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/profile', [ProfileController::class, 'show'])->name('api.v1.profile.show');
        Route::post('/profile', [ProfileController::class, 'store'])->name('api.v1.profile.store');
        Route::patch('/profile', [ProfileController::class, 'update'])->name('api.v1.profile.update');
        Route::put('/profile', [ProfileController::class, 'update']);

        // Profile Avatar & Cover Media Endpoints
        Route::post('/profile/avatar', [MediaController::class, 'uploadAvatar'])->name('api.v1.profile.avatar.upload');
        Route::delete('/profile/avatar', [MediaController::class, 'deleteAvatar'])->name('api.v1.profile.avatar.delete');
        Route::post('/profile/cover', [MediaController::class, 'uploadCover'])->name('api.v1.profile.cover.upload');
        Route::delete('/profile/cover', [MediaController::class, 'deleteCover'])->name('api.v1.profile.cover.delete');

        // Profile Appearance (Template & Theme) Endpoints
        Route::get('/profile/appearance', [ProfileController::class, 'getAppearance'])->name('api.v1.profile.appearance.get');
        Route::put('/profile/appearance', [ProfileController::class, 'updateAppearance'])->name('api.v1.profile.appearance.update');
        Route::patch('/profile/appearance', [ProfileController::class, 'updateAppearance']);

        // Media Management Endpoints
        Route::prefix('media')->group(function () {
            Route::get('/', [MediaController::class, 'index'])->name('api.v1.media.index');
            Route::post('/images', [MediaController::class, 'uploadImage'])->name('api.v1.media.upload.image');
            Route::delete('/{media}', [MediaController::class, 'destroy'])->name('api.v1.media.destroy');
        });

        // Authenticated Content Block Management Routes
        Route::prefix('profile/blocks')->group(function () {
            Route::get('/', [ProfileBlockController::class, 'index'])->name('api.v1.profile.blocks.index');
            Route::post('/', [ProfileBlockController::class, 'store'])->name('api.v1.profile.blocks.store');
            Route::post('/reorder', [ProfileBlockController::class, 'reorder'])->name('api.v1.profile.blocks.reorder');
            Route::get('/{block}', [ProfileBlockController::class, 'show'])->name('api.v1.profile.blocks.show');
            Route::patch('/{block}', [ProfileBlockController::class, 'update'])->name('api.v1.profile.blocks.update');
            Route::delete('/{block}', [ProfileBlockController::class, 'destroy'])->name('api.v1.profile.blocks.destroy');
        });

        // Authenticated Contact Submissions Inbox
        Route::prefix('profile/contact-submissions')->group(function () {
            Route::get('/', [ContactSubmissionController::class, 'index'])->name('api.v1.profile.contact.index');
            Route::delete('/{submission}', [ContactSubmissionController::class, 'destroy'])->name('api.v1.profile.contact.destroy');
        });

        // Authenticated Profile Custom Domains Management
        Route::prefix('profile/domains')->group(function () {
            Route::get('/', [ProfileDomainController::class, 'index'])->name('api.v1.profile.domains.index');
            Route::post('/', [ProfileDomainController::class, 'store'])->name('api.v1.profile.domains.store');
            Route::post('/{domain}/verify', [ProfileDomainController::class, 'verify'])->name('api.v1.profile.domains.verify');
            Route::post('/{domain}/activate', [ProfileDomainController::class, 'activate'])->name('api.v1.profile.domains.activate');
            Route::post('/{domain}/primary', [ProfileDomainController::class, 'setPrimary'])->name('api.v1.profile.domains.primary');
            Route::post('/{domain}/disable', [ProfileDomainController::class, 'disable'])->name('api.v1.profile.domains.disable');
            Route::delete('/{domain}', [ProfileDomainController::class, 'destroy'])->name('api.v1.profile.domains.destroy');
        });

        // Authenticated Profile Analytics Reporting
        Route::prefix('profile/analytics')->group(function () {
            Route::get('/overview', [ProfileAnalyticsController::class, 'overview'])->name('api.v1.profile.analytics.overview');
            Route::get('/timeseries', [ProfileAnalyticsController::class, 'timeseries'])->name('api.v1.profile.analytics.timeseries');
            Route::get('/blocks', [ProfileAnalyticsController::class, 'blocks'])->name('api.v1.profile.analytics.blocks');
            Route::get('/referrers', [ProfileAnalyticsController::class, 'referrers'])->name('api.v1.profile.analytics.referrers');
        });

        // Authenticated Profile SEO Management
        Route::prefix('profile/seo')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\ProfileSeoController::class, 'show'])->name('api.v1.profile.seo.show');
            Route::patch('/', [\App\Http\Controllers\Api\V1\ProfileSeoController::class, 'update'])->name('api.v1.profile.seo.update');
            Route::put('/', [\App\Http\Controllers\Api\V1\ProfileSeoController::class, 'update']);
        });

        // Authenticated Subscription & Entitlements
        Route::prefix('subscription')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'show'])->name('api.v1.subscription.show');
            Route::post('/checkout', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'checkout'])->name('api.v1.subscription.checkout');
            Route::post('/portal', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'portal'])->name('api.v1.subscription.portal');
            Route::post('/change-plan', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'changePlan'])->name('api.v1.subscription.change-plan');
            Route::post('/cancel', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'cancel'])->name('api.v1.subscription.cancel');
            Route::post('/resume', [\App\Http\Controllers\Api\V1\SubscriptionController::class, 'resume'])->name('api.v1.subscription.resume');
        });
        // Authenticated In-App Notifications
        Route::prefix('notifications')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\NotificationController::class, 'index'])->name('api.v1.notifications.index');
            Route::get('/unread-count', [\App\Http\Controllers\Api\V1\NotificationController::class, 'unreadCount'])->name('api.v1.notifications.unread-count');
            Route::patch('/read-all', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAllAsRead'])->name('api.v1.notifications.read-all');
            Route::patch('/{id}/read', [\App\Http\Controllers\Api\V1\NotificationController::class, 'markAsRead'])->name('api.v1.notifications.read');
            Route::delete('/{id}', [\App\Http\Controllers\Api\V1\NotificationController::class, 'destroy'])->name('api.v1.notifications.destroy');
        });

        // Authenticated Notification Preferences
        Route::prefix('profile/notification-preferences')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\NotificationPreferenceController::class, 'show'])->name('api.v1.profile.notification-preferences.show');
            Route::patch('/', [\App\Http\Controllers\Api\V1\NotificationPreferenceController::class, 'update'])->name('api.v1.profile.notification-preferences.update');
            Route::put('/', [\App\Http\Controllers\Api\V1\NotificationPreferenceController::class, 'update']);
        });

        // Authenticated Account & Security Management
        Route::prefix('account')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\V1\AccountController::class, 'show'])->name('api.v1.account.show');
            Route::patch('/', [\App\Http\Controllers\Api\V1\AccountController::class, 'update'])->name('api.v1.account.update');
            Route::put('/', [\App\Http\Controllers\Api\V1\AccountController::class, 'update']);
            Route::post('/change-password', [\App\Http\Controllers\Api\V1\AccountController::class, 'changePassword'])
                ->middleware('throttle:password-change')
                ->name('api.v1.account.change-password');
            Route::post('/confirm-password', [\App\Http\Controllers\Api\V1\AccountController::class, 'confirmPassword'])
                ->middleware('throttle:password-confirm')
                ->name('api.v1.account.confirm-password');
            Route::delete('/', [\App\Http\Controllers\Api\V1\AccountController::class, 'destroy'])
                ->middleware('throttle:account-delete')
                ->name('api.v1.account.destroy');

            // Account Sessions
            Route::get('/sessions', [\App\Http\Controllers\Api\V1\AccountSessionController::class, 'index'])->name('api.v1.account.sessions.index');
            Route::delete('/sessions/{id}', [\App\Http\Controllers\Api\V1\AccountSessionController::class, 'destroy'])
                ->middleware('throttle:session-revoke')
                ->name('api.v1.account.sessions.destroy');
            Route::post('/sessions/revoke-others', [\App\Http\Controllers\Api\V1\AccountSessionController::class, 'revokeOthers'])
                ->middleware('throttle:session-revoke')
                ->name('api.v1.account.sessions.revoke-others');

            // Account Security Audit Events
            Route::get('/security-events', [\App\Http\Controllers\Api\V1\AccountSecurityEventController::class, 'index'])->name('api.v1.account.security-events.index');
        });

        Route::get('/entitlements', [\App\Http\Controllers\Api\V1\EntitlementController::class, 'index'])->name('api.v1.entitlements.index');

        // Admin Control Plane & Governance
        Route::prefix('admin')->middleware('admin')->group(function () {
            // Platform Overview
            Route::get('/overview', [\App\Http\Controllers\Api\V1\Admin\AdminOverviewController::class, 'index'])->name('api.v1.admin.overview');

            // Platform Analytics (signups chart etc.)
            Route::get('/analytics/signups/monthly', [\App\Http\Controllers\Api\V1\Admin\AdminAnalyticsController::class, 'signupsMonthly'])->name('api.v1.admin.analytics.signups-monthly');

            // User Management
            Route::get('/users', [\App\Http\Controllers\Api\V1\Admin\AdminUserController::class, 'index'])->name('api.v1.admin.users.index');
            Route::post('/users', [\App\Http\Controllers\Api\V1\Admin\AdminUserController::class, 'store'])->name('api.v1.admin.users.store');
            Route::get('/users/{id}', [\App\Http\Controllers\Api\V1\Admin\AdminUserController::class, 'show'])->name('api.v1.admin.users.show');
            Route::patch('/users/{id}/status', [\App\Http\Controllers\Api\V1\Admin\AdminUserController::class, 'updateStatus'])->name('api.v1.admin.users.status');
            Route::patch('/users/{id}/role', [\App\Http\Controllers\Api\V1\Admin\AdminUserController::class, 'updateRole'])->name('api.v1.admin.users.role');
            Route::delete('/users/{id}', [\App\Http\Controllers\Api\V1\Admin\AdminUserController::class, 'destroy'])->name('api.v1.admin.users.destroy');
            Route::post('/users/{id}/impersonate', [\App\Http\Controllers\Api\V1\Admin\AdminUserController::class, 'impersonate'])->name('api.v1.admin.users.impersonate');

            // Profile Moderation
            Route::get('/profiles', [\App\Http\Controllers\Api\V1\Admin\AdminProfileController::class, 'index'])->name('api.v1.admin.profiles.index');
            Route::get('/profiles/{id}', [\App\Http\Controllers\Api\V1\Admin\AdminProfileController::class, 'show'])->name('api.v1.admin.profiles.show');
            Route::patch('/profiles/{id}/moderation', [\App\Http\Controllers\Api\V1\Admin\AdminProfileController::class, 'moderate'])->name('api.v1.admin.profiles.moderate');

            // Abuse Reports
            Route::get('/reports', [\App\Http\Controllers\Api\V1\Admin\AdminReportController::class, 'index'])->name('api.v1.admin.reports.index');
            Route::get('/reports/{id}', [\App\Http\Controllers\Api\V1\Admin\AdminReportController::class, 'show'])->name('api.v1.admin.reports.show');
            Route::patch('/reports/{id}', [\App\Http\Controllers\Api\V1\Admin\AdminReportController::class, 'update'])->name('api.v1.admin.reports.update');

            // Moderation Actions & Audit Logs
            Route::get('/moderation-actions', [\App\Http\Controllers\Api\V1\Admin\AdminModerationActionController::class, 'index'])->name('api.v1.admin.moderation-actions.index');
            Route::get('/audit-logs', [\App\Http\Controllers\Api\V1\Admin\AdminAuditLogController::class, 'index'])->name('api.v1.admin.audit-logs.index');

            // ── Validity & Expiry Management ──────────────────────────────────────────
            Route::post('/users/{id}/renew', [\App\Http\Controllers\Api\V1\Admin\AdminUserController::class, 'renew'])->name('api.v1.admin.users.renew');
            Route::get('/users/expiring', [\App\Http\Controllers\Api\V1\Admin\AdminExpiryController::class, 'expiring'])->name('api.v1.admin.users.expiring');
            Route::get('/users/expired', [\App\Http\Controllers\Api\V1\Admin\AdminExpiryController::class, 'expired'])->name('api.v1.admin.users.expired');

            // ── Validity Plans (Settings > Plan Prices) ───────────────────────────────
            Route::get('/validity-plans', [\App\Http\Controllers\Api\V1\Admin\AdminValidityPlanController::class, 'index'])->name('api.v1.admin.validity-plans.index');
            Route::patch('/validity-plans/{id}', [\App\Http\Controllers\Api\V1\Admin\AdminValidityPlanController::class, 'update'])->name('api.v1.admin.validity-plans.update');

            // ── Sales & Revenue (Accounts Menu) ───────────────────────────────────────
            Route::get('/sales/overview', [\App\Http\Controllers\Api\V1\Admin\AdminSalesController::class, 'overview'])->name('api.v1.admin.sales.overview');
            Route::get('/sales/chart/monthly', [\App\Http\Controllers\Api\V1\Admin\AdminSalesController::class, 'monthlyChart'])->name('api.v1.admin.sales.chart.monthly');
            Route::get('/sales/chart/daily', [\App\Http\Controllers\Api\V1\Admin\AdminSalesController::class, 'dailyChart'])->name('api.v1.admin.sales.chart.daily');
            Route::get('/sales/table', [\App\Http\Controllers\Api\V1\Admin\AdminSalesController::class, 'salesTable'])->name('api.v1.admin.sales.table');

            // ── Admin Settings ────────────────────────────────────────────────────────
            Route::patch('/settings/password', [\App\Http\Controllers\Api\V1\Admin\AdminSettingsController::class, 'changePassword'])->name('api.v1.admin.settings.password');

            // ── Access Requests (landing page requests) ───────────────────────────────
            Route::get('/access-requests', [\App\Http\Controllers\Api\V1\AccessRequestController::class, 'index'])->name('api.v1.admin.access-requests.index');
            Route::patch('/access-requests/{id}/review', [\App\Http\Controllers\Api\V1\AccessRequestController::class, 'review'])->name('api.v1.admin.access-requests.review');
            Route::delete('/access-requests/{id}', [\App\Http\Controllers\Api\V1\AccessRequestController::class, 'destroy'])->name('api.v1.admin.access-requests.destroy');

            // Operational Health & Reliability (Phase 17)
            Route::prefix('operations')->group(function () {
                Route::get('/health', [\App\Http\Controllers\Api\V1\Admin\OperationsController::class, 'health'])->name('api.v1.admin.operations.health');
                Route::get('/queue', [\App\Http\Controllers\Api\V1\Admin\OperationsController::class, 'queue'])->name('api.v1.admin.operations.queue');
                Route::get('/scheduler', [\App\Http\Controllers\Api\V1\Admin\OperationsController::class, 'scheduler'])->name('api.v1.admin.operations.scheduler');
                Route::get('/metrics', [\App\Http\Controllers\Api\V1\Admin\OperationsController::class, 'metrics'])->name('api.v1.admin.operations.metrics');
                Route::post('/metrics/refresh', [\App\Http\Controllers\Api\V1\Admin\OperationsController::class, 'refreshMetrics'])->name('api.v1.admin.operations.metrics.refresh');

                // Failed Jobs Management
                Route::get('/failed-jobs', [\App\Http\Controllers\Api\V1\Admin\FailedJobController::class, 'index'])->name('api.v1.admin.failed-jobs.index');
                Route::post('/failed-jobs/retry-all', [\App\Http\Controllers\Api\V1\Admin\FailedJobController::class, 'retryAll'])->name('api.v1.admin.failed-jobs.retry-all');
                Route::post('/failed-jobs/flush', [\App\Http\Controllers\Api\V1\Admin\FailedJobController::class, 'flush'])->name('api.v1.admin.failed-jobs.flush');
                Route::post('/failed-jobs/{id}/retry', [\App\Http\Controllers\Api\V1\Admin\FailedJobController::class, 'retry'])->name('api.v1.admin.failed-jobs.retry');
                Route::delete('/failed-jobs/{id}', [\App\Http\Controllers\Api\V1\Admin\FailedJobController::class, 'destroy'])->name('api.v1.admin.failed-jobs.destroy');
            });
        });
    });

    // Public Active Plans
    Route::get('/plans', [\App\Http\Controllers\Api\V1\PlanController::class, 'index'])->name('api.v1.plans.index');

    // Public Webhooks
    Route::post('/webhooks/stripe', [\App\Http\Controllers\Api\V1\WebhookController::class, 'handleStripe'])->name('api.v1.webhooks.stripe');

    // Internal Server-to-Server Protected Domain & Sitemap Resolution
    Route::get('/internal/domains/resolve', [InternalDomainResolutionController::class, 'resolve'])->name('api.v1.internal.domains.resolve');
    Route::get('/internal/sitemap-profiles', [\App\Http\Controllers\Api\V1\InternalSitemapController::class, 'index'])->name('api.v1.internal.sitemap-profiles');
});
