<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\AgentLead;
use App\Models\User;
use App\Services\AgentSiteService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The agent's own landing page (content, address, publish, images) and the leads it collects.
 * The domain is read-only here — only admins set it.
 */
class AgentSiteController extends Controller
{
    use ApiResponse;

    public function __construct(protected AgentSiteService $sites) {}

    /** The `agent` middleware also admits admins and impersonation sessions; this is for real agents only. */
    private function agent(Request $request): ?User
    {
        $user = $request->user();
        if (! $user || ! $user->isAgent() || ($request->hasSession() && $request->session()->has('impersonator_agent_id'))) {
            return null;
        }

        return $user;
    }

    private function forbidden(): JsonResponse
    {
        return $this->errorResponse('AGENT_ONLY', 'Only agents can manage a landing page.', null, Response::HTTP_FORBIDDEN);
    }

    public function show(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return $this->forbidden();
        }
        $site = $this->sites->forAgent($agent);
        $domain = $site->hasActiveDomain() ? $site->domain : null;

        return $this->successResponse([
            'site' => $this->sites->presentForOwner($site),
            'customers' => $this->sites->customerProfiles($agent->id)->map(fn ($p) => $this->sites->cardSummary($p, $domain))->values(),
            'unread_leads' => AgentLead::where('agent_id', $agent->id)->whereNull('read_at')->count(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return $this->forbidden();
        }
        $data = $request->validate([
            'content' => ['required', 'array'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:60'],
        ]);
        $site = $this->sites->updateContent($this->sites->forAgent($agent), $data['content'], $data['slug'] ?? null);

        return $this->successResponse(['site' => $this->sites->presentForOwner($site)]);
    }

    public function publish(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return $this->forbidden();
        }
        $data = $request->validate(['published' => ['required', 'boolean']]);
        $site = $this->sites->setPublished($this->sites->forAgent($agent), (bool) $data['published']);

        return $this->successResponse(['site' => $this->sites->presentForOwner($site)]);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return $this->forbidden();
        }
        $request->validate(['image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120']]);

        return $this->successResponse(['url' => $this->sites->uploadImage($agent, $request->file('image'))], [], 201);
    }

    public function leads(Request $request): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return $this->forbidden();
        }
        $page = AgentLead::where('agent_id', $agent->id)->latest()->paginate(max(1, min((int) $request->query('per_page', 20), 50)));

        return $this->successResponse([
            'items' => collect($page->items())->map(fn (AgentLead $l) => [
                'id' => $l->id,
                'name' => $l->name,
                'phone' => $l->phone,
                'email' => $l->email,
                'package' => $l->package,
                'message' => $l->message,
                'source_host' => $l->source_host,
                'is_read' => $l->read_at !== null,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->values(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'unread' => AgentLead::where('agent_id', $agent->id)->whereNull('read_at')->count(),
        ]);
    }

    public function markLeadRead(Request $request, string $id): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return $this->forbidden();
        }
        $lead = AgentLead::where('agent_id', $agent->id)->findOrFail($id);
        $lead->update(['read_at' => $lead->read_at ?? now()]);

        return $this->successResponse(['id' => $lead->id, 'is_read' => true]);
    }

    public function destroyLead(Request $request, string $id): JsonResponse
    {
        $agent = $this->agent($request);
        if (! $agent) {
            return $this->forbidden();
        }
        AgentLead::where('agent_id', $agent->id)->findOrFail($id)->delete();

        return $this->successResponse(null);
    }
}
