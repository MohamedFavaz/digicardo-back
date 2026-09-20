<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\HttpFoundation\Response;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(
            \App\Contracts\DnsVerificationServiceInterface::class,
            \App\Services\DnsVerificationService::class
        );

        $this->app->bind(
            \App\Contracts\CustomHostnameProviderInterface::class,
            function () {
                if (env('CLOUDFLARE_API_TOKEN') && env('CLOUDFLARE_ZONE_ID')) {
                    return new \App\Services\CloudflareCustomHostnameProvider();
                }
                return new \App\Services\NullCustomHostnameProvider();
            }
        );

        $this->app->bind(
            \App\Contracts\SubscriptionProviderInterface::class,
            function () {
                $provider = config('services.subscription.provider', 'null');
                if ($provider === 'stripe' && config('services.stripe.secret')) {
                    return new \App\Services\Subscriptions\StripeSubscriptionProvider();
                }
                return new \App\Services\NullSubscriptionProvider();
            }
        );

        $this->app->bind(
            \App\Contracts\EmailProviderInterface::class,
            function () {
                $mailer = config('mail.default', 'log');
                if ($mailer === 'null' || $mailer === 'log' || $mailer === 'array' || app()->environment('testing')) {
                    return new \App\Services\Email\NullEmailProvider();
                }
                return new \App\Services\Email\LaravelMailProvider();
            }
        );

        $this->app->singleton(
            \App\Contracts\ErrorTrackingProviderInterface::class,
            function () {
                $driver = config('observability.error_tracking.driver', 'null');
                if ($driver === 'log') {
                    return new \App\Services\Observability\LogErrorTrackingProvider();
                }
                return new \App\Services\Observability\NullErrorTrackingProvider();
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // General API rate limiter (60 req/min)
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many requests. Please slow down.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // Dedicated Login Rate Limiter (5 attempts per minute per IP + email)
        RateLimiter::for('login', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $throttleKey = $email . '|' . $request->ip();

            return Limit::perMinute(5)->by($throttleKey)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many login attempts. Please try again in a few minutes.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // Dedicated Registration Rate Limiter (3 attempts per minute per IP)
        RateLimiter::for('register', function (Request $request) {
            return Limit::perMinute(3)->by($request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many registration attempts from this IP address. Please try again later.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // General Auth Limiter fallback (10 req/min)
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip())->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many authentication attempts. Please try again later.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // Verification Notification Limiter (3 per 15 minutes per user/IP)
        RateLimiter::for('verification-notification', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return Limit::perMinutes(15, 3)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many verification email requests. Please wait a few minutes before trying again.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // Password Reset Limiter (3 per 15 minutes per email/IP)
        RateLimiter::for('password-reset', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $key = ($email ?: 'noemail') . '|' . $request->ip();
            return Limit::perMinutes(15, 3)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many password reset requests. Please wait before trying again.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // Password Confirmation Limiter (5 attempts per minute per user/IP)
        RateLimiter::for('password-confirm', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many confirmation attempts. Please wait a moment before trying again.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // Password Change Limiter (5 attempts per minute per user/IP)
        RateLimiter::for('password-change', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return Limit::perMinute(5)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many password change attempts. Please wait a minute before trying again.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // Account Deletion Limiter (3 attempts per 15 minutes per user/IP)
        RateLimiter::for('account-delete', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return Limit::perMinutes(15, 3)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many account deletion attempts. Please wait before trying again.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        // Session Revocation Limiter (10 per minute per user/IP)
        RateLimiter::for('session-revoke', function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();
            return Limit::perMinute(10)->by($key)->response(function () {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many session revocation requests. Please wait a moment.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            });
        });

        RateLimiter::for('public-write', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });

        RateLimiter::for('analytics', function (Request $request) {
            return Limit::perMinute(120)->by($request->ip());
        });
    }
}
