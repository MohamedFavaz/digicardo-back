<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\SubmitContactRequest;
use App\Http\Resources\Api\V1\ContactSubmissionResource;
use App\Models\ContactSubmission;
use App\Models\Profile;
use App\Services\ContactSubmissionService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ContactSubmissionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ContactSubmissionService $submissionService
    ) {}

    /**
     * Public endpoint to submit a message to a user profile contact block.
     */
    public function submit(SubmitContactRequest $request, string $username): JsonResponse
    {
        $profile = Profile::where('username', strtolower(trim($username)))
            ->where('is_public', true)
            ->first();

        if (!$profile) {
            throw new NotFoundHttpException('Profile not found.');
        }

        $this->submissionService->submit(
            $profile,
            $request->validated(),
            $request->ip() ?: '127.0.0.1'
        );

        return $this->successResponse(
            null,
            ['message' => 'Thank you! Your message has been sent successfully.'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Authenticated endpoint to list all contact submissions for the owner's profile.
     */
    public function index(Request $request): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'Profile not found.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        $submissions = ContactSubmission::where('profile_id', $profile->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return $this->successResponse(
            ContactSubmissionResource::collection($submissions)
        );
    }

    /**
     * Authenticated endpoint to delete a contact submission.
     */
    public function destroy(Request $request, ContactSubmission $submission): JsonResponse
    {
        $profile = $request->user()->profile;

        if (!$profile || $submission->profile_id !== $profile->id) {
            throw new AccessDeniedHttpException('You do not have access to this submission.');
        }

        $submission->delete();

        return $this->successResponse(
            null,
            ['message' => 'Submission deleted successfully.']
        );
    }
}
