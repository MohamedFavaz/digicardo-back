<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\AgentLead;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\AgentSiteService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin control of an agent's site: the agent domain (set / check DNS / activate / remove)
 * and switching the landing page off.
 */
class AdminAgentSiteController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected AgentSiteService $sites,
        protected AdminAuditService $audit,
    ) {}

    private function agent(string $id): User
    {
        return User::where('role', UserRole::Agent->value)->findOrFail($id);
    }

    private function payload(User $agent): array
    {
        $site = $this->sites->forAgent($agent);

        return [
            'site' => $this->sites->presentForOwner($site),
            'dns' => $this->sites->dnsInstructions(),
            'customers' => $this->sites->customerProfiles($agent->id)->count(),
            'leads' => AgentLead::where('agent_id', $agent->id)->count(),
        ];
    }

    public function show(string $id): JsonResponse
    {
        return $this->successResponse($this->payload($this->agent($id)));
    }

    public function setDomain(Request $request, string $id): JsonResponse
    {
        $agent = $this->agent($id);
        $data = $request->validate(['domain' => ['present', 'nullable', 'string', 'max:253']]);
        $site = $this->sites->setDomain($this->sites->forAgent($agent), $data['domain']);
        $this->audit->log($request->user(), $site->domain ? 'agent_domain.set' : 'agent_domain.removed', 'agent_site', $site->id, ['agent_id' => $agent->id, 'domain' => $site->domain]);

        return $this->successResponse($this->payload($agent));
    }

    public function checkDomain(Request $request, string $id): JsonResponse
    {
        $agent = $this->agent($id);
        $site = $this->sites->checkDomain($this->sites->forAgent($agent));
        $this->audit->log($request->user(), 'agent_domain.checked', 'agent_site', $site->id, ['domain' => $site->domain, 'status' => $site->domain_status]);

        return $this->successResponse($this->payload($agent));
    }

    public function activateDomain(Request $request, string $id): JsonResponse
    {
        $agent = $this->agent($id);
        $site = $this->sites->activate($this->sites->forAgent($agent));
        $this->audit->log($request->user(), 'agent_domain.activated', 'agent_site', $site->id, ['domain' => $site->domain]);

        return $this->successResponse($this->payload($agent));
    }

    public function removeDomain(Request $request, string $id): JsonResponse
    {
        $agent = $this->agent($id);
        $site = $this->sites->forAgent($agent);
        $old = $site->domain;
        $this->sites->removeDomain($site);
        $this->audit->log($request->user(), 'agent_domain.removed', 'agent_site', $site->id, ['domain' => $old]);

        return $this->successResponse($this->payload($agent));
    }

    public function setLanding(Request $request, string $id): JsonResponse
    {
        $agent = $this->agent($id);
        $data = $request->validate(['disabled' => ['required', 'boolean']]);
        $site = $this->sites->setLandingDisabled($this->sites->forAgent($agent), (bool) $data['disabled']);
        $this->audit->log($request->user(), $site->landing_disabled ? 'agent_landing.disabled' : 'agent_landing.enabled', 'agent_site', $site->id, ['agent_id' => $agent->id]);

        return $this->successResponse($this->payload($agent));
    }
}
