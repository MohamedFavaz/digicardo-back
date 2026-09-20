<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\CreateProfileRequest;
use App\Http\Requests\Profile\UpdateAppearanceRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\Api\V1\ProfileResource;
use App\Services\ProfileService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProfileController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly ProfileService $profileService
    ) {}

    /**
     * Retrieve the authenticated user's profile.
     */
    public function show(Request $request): JsonResponse
    {
        $profile = $this->profileService->getForUser($request->user());

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'Profile has not been created yet.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->successResponse(new ProfileResource($profile));
    }

    /**
     * Create a profile for the authenticated user.
     */
    public function store(CreateProfileRequest $request): JsonResponse
    {
        $profile = $this->profileService->createForUser(
            $request->user(),
            $request->validated()
        );

        return $this->successResponse(
            new ProfileResource($profile),
            ['message' => 'Profile created successfully.'],
            Response::HTTP_CREATED
        );
    }

    /**
     * Update the authenticated user's profile.
     */
    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $profile = $this->profileService->updateForUser(
            $request->user(),
            $request->validated()
        );

        return $this->successResponse(
            new ProfileResource($profile),
            ['message' => 'Profile updated successfully.']
        );
    }

    /**
     * Retrieve current appearance (template & theme) settings for the profile.
     */
    public function getAppearance(Request $request): JsonResponse
    {
        $profile = $this->profileService->getForUser($request->user());

        if (!$profile) {
            return $this->errorResponse(
                'NOT_FOUND',
                'Profile has not been created yet.',
                null,
                Response::HTTP_NOT_FOUND
            );
        }

        return $this->successResponse([
            'template_id' => $profile->template_id,
            'theme_tokens' => $profile->theme_tokens,
            'version' => (int) $profile->version,
        ]);
    }

    /**
     * Update profile appearance (template selection and theme customization).
     */
    public function updateAppearance(UpdateAppearanceRequest $request): JsonResponse
    {
        $data = $request->validated();

        // Merge the raw custom_options from input into the validated data.
        // Laravel's validated() only returns explicitly-ruled keys, so nested
        // arrays inside custom_options (products, banner_images, social_urls, etc.)
        // would be stripped if we rely solely on validated(). We preserve the full
        // custom_options blob here — the Zod schema on the frontend already enforced
        // structural correctness before submission.
        $rawCustomOptions = $request->input('theme_tokens.custom_options');
        if (isset($data['theme_tokens']) && is_array($data['theme_tokens']) && $rawCustomOptions !== null) {
            $data['theme_tokens']['custom_options'] = is_array($rawCustomOptions) ? $rawCustomOptions : [];
        }

        $profile = $this->profileService->updateAppearanceForUser(
            $request->user(),
            $data
        );

        return $this->successResponse(
            new ProfileResource($profile),
            ['message' => 'Appearance updated successfully.']
        );
    }
}
