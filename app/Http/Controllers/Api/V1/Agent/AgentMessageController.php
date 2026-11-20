<?php

namespace App\Http\Controllers\Api\V1\Agent;

use App\Http\Controllers\Controller;
use App\Models\BroadcastMessage;
use App\Models\User;
use App\Services\BroadcastService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Agent messages: an agent can message all, or some, of the customers they created. Nobody else.
 */
class AgentMessageController extends Controller
{
    use ApiResponse;

    public function __construct(protected BroadcastService $broadcasts) {}

    /** The `agent` middleware also admits admins and impersonation sessions; messaging is for real agents only. */
    private function agentOrNull(Request $request): ?User
    {
        $user = $request->user();
        if (! $user || ! $user->isAgent() || ($request->hasSession() && $request->session()->has('impersonator_agent_id'))) {
            return null;
        }

        return $user;
    }

    private function forbidden(): JsonResponse
    {
        return $this->errorResponse('AGENT_ONLY', 'Only agents can message their customers.', null, Response::HTTP_FORBIDDEN);
    }

    public function index(Request $request): JsonResponse
    {
        $agent = $this->agentOrNull($request);
        if (! $agent) {
            return $this->forbidden();
        }
        $perPage = max(1, min((int) $request->query('per_page', 15), 50));
        $page = $this->broadcasts->history($agent, 'agent', $perPage);

        return $this->successResponse([
            'items' => collect($page->items())->map(fn ($b) => $this->broadcasts->present($b))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
            'customers' => $this->broadcasts->recipientsQuery($agent, 'agent', 'my_customers')->count(),
        ]);
    }

    /** The agent's own customers, for the "specific customers" picker. */
    public function recipients(Request $request): JsonResponse
    {
        $agent = $this->agentOrNull($request);
        if (! $agent) {
            return $this->forbidden();
        }

        return $this->successResponse([
            'items' => $this->broadcasts->searchRecipients($agent, 'agent', (string) $request->query('search', ''), null, 50),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $agent = $this->agentOrNull($request);
        if (! $agent) {
            return $this->forbidden();
        }

        $data = $request->validate([
            'audience' => ['required', 'string', Rule::in(BroadcastService::AGENT_AUDIENCES)],
            'user_ids' => ['required_if:audience,selected', 'array', 'max:500'],
            'user_ids.*' => ['string', 'size:26'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'link_url' => ['nullable', 'string', 'max:500'],
        ]);

        $broadcast = $this->broadcasts->send($agent, 'agent', $data);

        return $this->successResponse(
            ['message' => $this->broadcasts->present($broadcast->load('sender'))],
            ['message' => "Sent to {$broadcast->recipient_count} " . ($broadcast->recipient_count === 1 ? 'customer' : 'customers') . '.'],
            201,
        );
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $agent = $this->agentOrNull($request);
        if (! $agent) {
            return $this->forbidden();
        }
        $broadcast = BroadcastMessage::where('sender_id', $agent->id)->where('sender_role', 'agent')->findOrFail($id);

        return $this->successResponse(['message' => $this->broadcasts->present($this->broadcasts->retract($broadcast)->load('sender'))]);
    }
}
