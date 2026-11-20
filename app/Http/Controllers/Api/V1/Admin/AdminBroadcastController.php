<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Models\BroadcastMessage;
use App\Models\User;
use App\Services\AdminAuditService;
use App\Services\BroadcastService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin messages: send to everyone, all users, all agents, one agent's customers, or chosen people.
 */
class AdminBroadcastController extends Controller
{
    use ApiResponse;

    public function __construct(
        protected BroadcastService $broadcasts,
        protected AdminAuditService $audit,
    ) {}

    /** Sent messages (admin and agent), newest first. ?sender=admin|agent filters. */
    public function index(Request $request): JsonResponse
    {
        $sender = in_array($request->query('sender'), ['admin', 'agent'], true) ? $request->query('sender') : null;
        $perPage = max(1, min((int) $request->query('per_page', 15), 50));
        $page = $this->broadcasts->history(null, $sender, $perPage);

        return $this->successResponse([
            'items' => collect($page->items())->map(fn ($b) => $this->broadcasts->present($b))->values(),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    /** Head-counts for each audience, plus agents (with customer counts) for the "agent's customers" choice. */
    public function audiences(Request $request): JsonResponse
    {
        $admin = $request->user();
        $count = fn (string $audience) => $this->broadcasts->recipientsQuery($admin, 'admin', $audience)->count();

        $customerCounts = User::query()
            ->where('role', UserRole::User->value)
            ->where('status', UserStatus::Active->value)
            ->whereNotNull('agent_id')
            ->selectRaw('agent_id, count(*) as total')
            ->groupBy('agent_id')
            ->pluck('total', 'agent_id');

        $agents = User::query()
            ->where('role', UserRole::Agent->value)
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn (User $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'customers' => (int) ($customerCounts[$a->id] ?? 0),
            ]);

        return $this->successResponse([
            'counts' => [
                'everyone' => $count('everyone'),
                'users' => $count('users'),
                'agents' => $count('agents'),
            ],
            'agents' => $agents,
        ]);
    }

    /** People search for the "specific people" picker. ?search=&role=user|agent */
    public function recipients(Request $request): JsonResponse
    {
        return $this->successResponse([
            'items' => $this->broadcasts->searchRecipients(
                $request->user(),
                'admin',
                (string) $request->query('search', ''),
                $request->query('role'),
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'string', Rule::in(BroadcastService::ADMIN_AUDIENCES)],
            'user_ids' => ['required_if:audience,selected', 'array', 'max:500'],
            'user_ids.*' => ['string', 'size:26'],
            'agent_id' => ['required_if:audience,agent_customers', 'nullable', 'string', 'size:26'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:2000'],
            'link_url' => ['nullable', 'string', 'max:500'],
            'send_email' => ['sometimes', 'boolean'],
        ]);

        $broadcast = $this->broadcasts->send($request->user(), 'admin', $data);

        $this->audit->log($request->user(), 'broadcast.sent', 'broadcast_message', $broadcast->id, [
            'audience' => $broadcast->audience,
            'recipient_count' => $broadcast->recipient_count,
            'emailed' => $broadcast->emailed,
        ]);

        return $this->successResponse(
            ['message' => $this->broadcasts->present($broadcast->load('sender'))],
            ['message' => "Sent to {$broadcast->recipient_count} " . ($broadcast->recipient_count === 1 ? 'person' : 'people') . '.'],
            201,
        );
    }

    /** Unsend: removes the message from everyone's notifications (any sender — admins moderate agents too). */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $broadcast = BroadcastMessage::findOrFail($id);
        $broadcast = $this->broadcasts->retract($broadcast);

        $this->audit->log($request->user(), 'broadcast.retracted', 'broadcast_message', $broadcast->id, [
            'sender_role' => $broadcast->sender_role,
        ]);

        return $this->successResponse(['message' => $this->broadcasts->present($broadcast->load('sender'))]);
    }
}
