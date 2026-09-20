<?php

use App\Exceptions\ConflictException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Unauthenticated guests should receive JSON 401 rather than being redirected to a Blade route
        $middleware->redirectGuestsTo(fn () => null);

        // Stateful domains for Sanctum SPA / BFF auth
        $middleware->statefulApi();

        $middleware->appendToGroup('api', [
            \App\Http\Middleware\AssignRequestId::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        // Trust proxies for Cloudflare / reverse proxy
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Standardized JSON Exception Mapping for all API routes
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'VALIDATION_ERROR',
                        'message' => 'The given data was invalid.',
                        'details' => $e->errors(),
                    ],
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                // Use the actual exception message (e.g. from AuthService custom throws)
                // Fall back to generic 'Unauthenticated.' only when the message is the default empty one
                $message = $e->getMessage() ?: 'Unauthenticated.';
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'UNAUTHENTICATED',
                        'message' => $message,
                    ],
                ], Response::HTTP_UNAUTHORIZED);
            }
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FORBIDDEN',
                        'message' => $e->getMessage() ?: 'You are not authorized to perform this action.',
                    ],
                ], Response::HTTP_FORBIDDEN);
            }
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'NOT_FOUND',
                        'message' => 'Resource not found.',
                    ],
                ], Response::HTTP_NOT_FOUND);
            }
        });

        $exceptions->render(function (\App\Exceptions\FeatureLimitExceededException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FEATURE_LIMIT_REACHED',
                        'message' => $e->getMessage(),
                        'details' => $e->getDetails(),
                    ],
                ], Response::HTTP_FORBIDDEN);
            }
        });

        $exceptions->render(function (\App\Exceptions\FeatureNotAvailableException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'FEATURE_NOT_AVAILABLE',
                        'message' => $e->getMessage(),
                        'details' => $e->getDetails(),
                    ],
                ], Response::HTTP_FORBIDDEN);
            }
        });
        $exceptions->render(function (\App\Exceptions\SubscriptionDowngradeBlockedException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SUBSCRIPTION_DOWNGRADE_BLOCKED',
                        'message' => $e->getMessage(),
                        'details' => $e->getDetails(),
                    ],
                ], Response::HTTP_CONFLICT);
            }
        });

        $exceptions->render(function (\App\Exceptions\InvalidWebhookSignatureException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'INVALID_WEBHOOK_SIGNATURE',
                        'message' => $e->getMessage(),
                    ],
                ], Response::HTTP_BAD_REQUEST);
            }
        });

        $exceptions->render(function (\App\Exceptions\PaymentProviderException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $error = [
                    'code' => $e->getErrorCode(),
                    'message' => $e->getMessage(),
                ];
                if ($e->getDetails() !== null) {
                    $error['details'] = $e->getDetails();
                }
                return response()->json([
                    'success' => false,
                    'error' => $error,
                ], $e->getStatusCode());
            }
        });

        $exceptions->render(function (ConflictException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $errorData = [
                    'code' => 'CONFLICT',
                    'message' => $e->getMessage(),
                ];
                if ($e->getCurrentVersion() !== null) {
                    $errorData['current_version'] = $e->getCurrentVersion();
                }
                return response()->json([
                    'success' => false,
                    'error' => $errorData,
                ], Response::HTTP_CONFLICT);
            }
        });

        $exceptions->render(function (ThrottleRequestsException|TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many requests. Please slow down.',
                    ],
                ], Response::HTTP_TOO_MANY_REQUESTS);
            }
        });

        $exceptions->render(function (HttpException $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'HTTP_ERROR',
                        'message' => $e->getMessage() ?: 'An HTTP error occurred.',
                    ],
                ], $e->getStatusCode());
            }
        });

        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->wantsJson()) {
                $isDebug = config('app.debug', false);

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'SERVER_ERROR',
                        'message' => $isDebug ? $e->getMessage() : 'An unexpected server error occurred.',
                        'details' => $isDebug ? [
                            'exception' => get_class($e),
                            'file' => $e->getFile(),
                            'line' => $e->getLine(),
                        ] : null,
                    ],
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        });
    })->create();
