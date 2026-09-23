<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Services\ValidityPlanService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminValidityPlanController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected ValidityPlanService $planService
    ) {}

    /** List all validity plans (for admin settings). */
    public function index(): JsonResponse
    {
        $plans = $this->planService->listAll()->map(fn ($p) => [
            'id'              => $p->id,
            'name'            => $p->name,
            'months'          => $p->months,
            'price'           => (float) $p->price,
            'currency_symbol' => $p->currency_symbol,
            'formatted_price' => $p->formattedPrice(),
            'is_active'       => $p->is_active,
            'sort_order'      => $p->sort_order,
        ]);

        return $this->successResponse(['plans' => $plans]);
    }

    /** Create a new validity plan. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'            => 'required|string|max:100',
            'months'          => 'required|integer|min:1|max:120',
            'price'           => 'required|numeric|min:0|max:999999',
            'currency_symbol' => 'sometimes|string|max:5',
            'is_active'       => 'sometimes|boolean',
        ]);

        $plan = $this->planService->createPlan($data);

        return $this->successResponse([
            'plan' => [
                'id'              => $plan->id,
                'name'            => $plan->name,
                'months'          => $plan->months,
                'price'           => (float) $plan->price,
                'currency_symbol' => $plan->currency_symbol,
                'formatted_price' => $plan->formattedPrice(),
                'is_active'       => $plan->is_active,
            ],
        ], ['message' => 'Plan created successfully.'], 201);
    }

    /** Update plan price, currency, or active state. */
    public function update(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'price'           => 'sometimes|numeric|min:0|max:999999',
            'currency_symbol' => 'sometimes|string|max:5',
            'is_active'       => 'sometimes|boolean',
        ]);

        if (array_key_exists('is_active', $data)) {
            $plan = $this->planService->toggleActive($id, (bool) $data['is_active']);
        }

        if (array_key_exists('price', $data)) {
            $plan = $this->planService->updatePrice(
                $id,
                (float) $data['price'],
                $data['currency_symbol'] ?? '₹'
            );
        }

        return $this->successResponse([
            'plan' => [
                'id'              => $plan->id,
                'name'            => $plan->name,
                'months'          => $plan->months,
                'price'           => (float) $plan->price,
                'currency_symbol' => $plan->currency_symbol,
                'formatted_price' => $plan->formattedPrice(),
                'is_active'       => $plan->is_active,
            ],
        ], ['message' => 'Plan updated successfully.']);
    }

    /** Delete a validity plan. */
    public function destroy(string $id): JsonResponse
    {
        $this->planService->deletePlan($id);

        return $this->successResponse(null, ['message' => 'Plan deleted successfully.']);
    }
}
