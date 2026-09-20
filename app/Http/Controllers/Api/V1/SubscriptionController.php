<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelSubscriptionRequest;
use App\Http\Requests\ChangeSubscriptionPlanRequest;
use App\Http\Requests\CheckoutSubscriptionRequest;
use App\Http\Resources\Api\V1\SubscriptionResource;
use App\Services\Subscriptions\SubscriptionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SubscriptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected SubscriptionService $subscriptionService
    ) {}

    /**
     * Get the authenticated user's current subscription details.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $this->subscriptionService->getActiveSubscription($user);

        if (!$subscription) {
            return $this->successResponse([
                'subscription' => null,
                'plan_code' => 'free',
                'is_active' => true,
            ]);
        }

        return $this->successResponse(
            new SubscriptionResource($subscription),
            [],
            Response::HTTP_OK
        );
    }

    /**
     * Create a checkout session for purchasing a paid subscription tier.
     */
    public function checkout(CheckoutSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        $plan = $request->validated('plan');
        $interval = $request->validated('interval', 'monthly');

        $sessionData = $this->subscriptionService->checkout($user, $plan, $interval);

        return $this->successResponse(
            $sessionData->toArray(),
            [],
            Response::HTTP_OK
        );
    }

    /**
     * Create a customer billing portal session URL.
     */
    public function portal(Request $request): JsonResponse
    {
        $user = $request->user();
        $portalData = $this->subscriptionService->portal($user);

        return $this->successResponse(
            $portalData->toArray(),
            [],
            Response::HTTP_OK
        );
    }

    /**
     * Change an existing subscription plan or billing interval.
     */
    public function changePlan(ChangeSubscriptionPlanRequest $request): JsonResponse
    {
        $user = $request->user();
        $plan = $request->validated('plan');
        $interval = $request->validated('interval', 'monthly');

        $subscription = $this->subscriptionService->changePlan($user, $plan, $interval);

        return $this->successResponse(
            new SubscriptionResource($subscription),
            [],
            Response::HTTP_OK
        );
    }

    /**
     * Cancel an active subscription.
     */
    public function cancel(CancelSubscriptionRequest $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $this->subscriptionService->cancel($user);

        return $this->successResponse(
            new SubscriptionResource($subscription),
            [],
            Response::HTTP_OK
        );
    }

    /**
     * Resume a canceled subscription if still within active period.
     */
    public function resume(Request $request): JsonResponse
    {
        $user = $request->user();
        $subscription = $this->subscriptionService->resume($user);

        return $this->successResponse(
            new SubscriptionResource($subscription),
            [],
            Response::HTTP_OK
        );
    }
}
