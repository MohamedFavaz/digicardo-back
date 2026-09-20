<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Domain\StoreDomainRequest;
use App\Http\Resources\Api\V1\ProfileDomainResource;
use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Services\DomainService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ProfileDomainController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly DomainService $domainService
    ) {}

    /**
     * Get the authenticated user's profile or throw 404.
     */
    private function getAuthenticatedProfile(Request $request): Profile
    {
        $profile = Profile::where('user_id', $request->user()->id)->first();

        if (! $profile) {
            throw new NotFoundHttpException('Profile not found for this user.');
        }

        return $profile;
    }

    /**
     * Find a domain belonging strictly to the user's profile.
     */
    private function findUserDomain(Profile $profile, string $domainId): ProfileDomain
    {
        $domain = ProfileDomain::where('id', $domainId)
            ->where('profile_id', $profile->id)
            ->first();

        if (! $domain) {
            throw new NotFoundHttpException('Custom domain not found.');
        }

        return $domain;
    }

    /**
     * List all custom domains for the profile.
     */
    public function index(Request $request): JsonResponse
    {
        $profile = $this->getAuthenticatedProfile($request);
        $domains = $profile->domains()->orderBy('created_at', 'desc')->get();

        return $this->successResponse(
            ProfileDomainResource::collection($domains)
        );
    }

    /**
     * Register a new custom domain for verification.
     */
    public function store(StoreDomainRequest $request): JsonResponse
    {
        $profile = $this->getAuthenticatedProfile($request);
        $user = $request->user();

        $domain = $this->domainService->createDomain($profile, $user, $request->validated('domain'));

        return $this->successResponse(
            new ProfileDomainResource($domain),
            ['message' => 'Custom domain registered. Please configure DNS TXT verification.'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Trigger DNS TXT verification for a domain.
     */
    public function verify(Request $request, string $id): JsonResponse
    {
        $profile = $this->getAuthenticatedProfile($request);
        $domain = $this->findUserDomain($profile, $id);

        $domain = $this->domainService->verifyDomain($domain);

        $isVerified = $domain->status->isVerified();

        return $this->successResponse(
            new ProfileDomainResource($domain),
            [
                'message' => $isVerified
                    ? 'Domain ownership verified successfully.'
                    : 'Domain verification failed. DNS record not found or still propagating.',
                'verified' => $isVerified,
            ]
        );
    }

    /**
     * Activate a verified custom domain.
     */
    public function activate(Request $request, string $id): JsonResponse
    {
        $profile = $this->getAuthenticatedProfile($request);
        $domain = $this->findUserDomain($profile, $id);

        $domain = $this->domainService->activateDomain($domain);

        return $this->successResponse(
            new ProfileDomainResource($domain),
            ['message' => 'Custom domain activated successfully.']
        );
    }

    /**
     * Set a domain as the primary domain for the profile.
     */
    public function setPrimary(Request $request, string $id): JsonResponse
    {
        $profile = $this->getAuthenticatedProfile($request);
        $domain = $this->findUserDomain($profile, $id);

        $domain = $this->domainService->setPrimaryDomain($domain);

        return $this->successResponse(
            new ProfileDomainResource($domain),
            ['message' => 'Domain set as primary profile address.']
        );
    }

    /**
     * Disable an active custom domain.
     */
    public function disable(Request $request, string $id): JsonResponse
    {
        $profile = $this->getAuthenticatedProfile($request);
        $domain = $this->findUserDomain($profile, $id);

        $domain = $this->domainService->disableDomain($domain);

        return $this->successResponse(
            new ProfileDomainResource($domain),
            ['message' => 'Custom domain disabled.']
        );
    }

    /**
     * Delete and unbind a custom domain.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $profile = $this->getAuthenticatedProfile($request);
        $domain = $this->findUserDomain($profile, $id);

        $this->domainService->deleteDomain($domain);

        return $this->successResponse(
            ['deleted' => true],
            ['message' => 'Custom domain removed successfully.']
        );
    }
}
