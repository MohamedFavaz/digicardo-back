<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Http\Controllers\Controller;
use App\Models\AgentLead;
use App\Models\AgentSite;
use App\Services\AgentSiteService;
use App\Services\NotificationService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Public agent landing pages (/partner/{slug} or the agent's domain root) and their enquiry form.
 */
class PublicPartnerController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AgentSiteService $sites,
        protected NotificationService $notifications,
    ) {}

    public function show(string $slug): JsonResponse
    {
        $payload = $this->sites->publicPayload($slug);
        if (! $payload) {
            return $this->errorResponse('NOT_FOUND', 'This page is not available.', null, 404);
        }

        return $this->successResponse($payload, [], 200, ['Cache-Control' => 'public, max-age=60, s-maxage=300, stale-while-revalidate=86400']);
    }

    public function lead(Request $request, string $slug): JsonResponse
    {
        $site = AgentSite::where('slug', strtolower($slug))->first();
        if (! $site || ! $site->isLandingLive() || ! ($site->content['lead_form']['enabled'] ?? true)) {
            return $this->errorResponse('NOT_FOUND', 'This page is not available.', null, 404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'phone' => ['nullable', 'string', 'max:24', 'regex:/^\+?[0-9 ()-]{7,24}$/'],
            'email' => ['nullable', 'email', 'max:120'],
            'package' => ['nullable', 'string', 'max:60'],
            'message' => ['nullable', 'string', 'max:1000'],
            // Honeypot: real people leave it empty.
            'company_website' => ['nullable', 'max:0'],
        ]);
        if (empty($data['phone']) && empty($data['email'])) {
            throw ValidationException::withMessages(['phone' => 'Add a phone number or an email so we can reach you.']);
        }

        $lead = AgentLead::create([
            'agent_id' => $site->agent_id,
            'name' => trim($data['name']),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'package' => $data['package'] ?? null,
            'message' => $data['message'] ?? null,
            'source_host' => substr((string) $request->header('x-forwarded-host', $request->getHost()), 0, 253),
        ]);

        try {
            $this->notifications->createForUser(
                $site->agent,
                NotificationType::ContactReceived,
                NotificationCategory::Contact,
                "New enquiry from {$lead->name}",
                trim(implode(' · ', array_filter([$lead->package, $lead->phone, $lead->email]))) ?: 'Someone asked about a card on your page.',
                ['lead_id' => $lead->id, 'link_url' => '/agent/leads'],
                true,
            );
        } catch (\Throwable) {
            // The lead is saved; a failed notification must not lose it.
        }

        return $this->successResponse(['message' => 'Thanks! We will get back to you soon.'], [], 201);
    }
}
