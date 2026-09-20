<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AccessRequest;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AccessRequestController extends Controller
{
    use ApiResponse;

    /**
     * Public: Submit a new access request from the landing page or register page.
     */
    public function submit(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'             => ['required', 'string', 'max:255'],
            'email'            => ['required', 'email', 'max:255'],
            'phone'            => ['nullable', 'string', 'max:30'],
            'mobile'           => ['nullable', 'string', 'max:30'],
            'business_name'    => ['nullable', 'string', 'max:255'],
            'desired_handle'   => ['nullable', 'string', 'max:100'],
            'desired_username' => ['nullable', 'string', 'max:100'],
            'message'          => ['nullable', 'string', 'max:2000'],
        ]);

        $phone = $validated['mobile'] ?? $validated['phone'] ?? null;
        $handle = $validated['desired_username'] ?? $validated['desired_handle'] ?? null;

        // Build composite message containing desired handle if provided
        $fullMessage = $validated['message'] ?? null;
        if ($handle) {
            $handlePrefix = 'Desired Handle: @' . ltrim($handle, '@');
            $fullMessage = $fullMessage ? ($handlePrefix . ' — ' . $fullMessage) : $handlePrefix;
        }

        $email = strtolower(trim($validated['email']));
        $existing = AccessRequest::where('email', $email)->first();

        if ($existing) {
            if ($existing->status === 'approved') {
                return $this->errorResponse(
                    'ALREADY_APPROVED',
                    'An account has already been approved for this email address. Please sign in.',
                    null,
                    Response::HTTP_CONFLICT
                );
            }

            // Update existing pending/rejected request with newest customer-typed details
            $existing->update([
                'name'          => trim($validated['name']),
                'phone'         => $phone ?: $existing->phone,
                'business_name' => $validated['business_name'] ?? $existing->business_name,
                'message'       => $fullMessage ?? $existing->message,
                'status'        => 'pending',
                'reviewed_by'   => null,
                'review_note'   => null,
                'reviewed_at'   => null,
            ]);

            return $this->successResponse(
                ['id' => $existing->id],
                ['message' => 'Access request updated successfully. We will reach out shortly!']
            );
        }

        $accessRequest = AccessRequest::create([
            'id'            => (string) Str::ulid(),
            'name'          => trim($validated['name']),
            'email'         => $email,
            'phone'         => $phone,
            'business_name' => $validated['business_name'] ?? null,
            'message'       => $fullMessage,
            'status'        => 'pending',
        ]);

        return $this->successResponse(
            ['id' => $accessRequest->id],
            ['message' => 'Access request submitted successfully. We will reach out shortly!'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Admin: List all access requests with optional status filter and rich search.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AccessRequest::query()->latest();

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('search')) {
            $search = '%' . $request->input('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('email', 'like', $search)
                  ->orWhere('phone', 'like', $search)
                  ->orWhere('business_name', 'like', $search)
                  ->orWhere('message', 'like', $search);
            });
        }

        $perPage = min((int) $request->input('per_page', 50), 100);
        $paginator = $query->paginate($perPage);

        return $this->successResponse([
            'items'      => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
            ],
        ]);
    }

    /**
     * Admin: Approve or reject an access request.
     */
    public function review(Request $request, string $id): JsonResponse
    {
        $accessRequest = AccessRequest::findOrFail($id);

        $validated = $request->validate([
            'action'      => ['required', 'in:approve,reject'],
            'review_note' => ['nullable', 'string', 'max:500'],
        ]);

        $accessRequest->update([
            'status'      => $validated['action'] === 'approve' ? 'approved' : 'rejected',
            'reviewed_by' => $request->user()?->id,
            'review_note' => $validated['review_note'] ?? null,
            'reviewed_at' => now(),
        ]);

        return $this->successResponse(
            ['status' => $accessRequest->status],
            ['message' => 'Request ' . $accessRequest->status . ' successfully.']
        );
    }

    /**
     * Admin: Delete an access request.
     */
    public function destroy(string $id): JsonResponse
    {
        AccessRequest::findOrFail($id)->delete();
        return $this->successResponse(null, ['message' => 'Access request deleted.']);
    }
}
