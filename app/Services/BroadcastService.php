<?php

namespace App\Services;

use App\Enums\NotificationCategory;
use App\Enums\NotificationType;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Jobs\SendEmailJob;
use App\Models\BroadcastMessage;
use App\Models\Notification;
use App\Models\User;
use App\Services\Email\EmailDeliveryService;
use App\Services\Email\EmailTemplateService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sends a message from an admin or an agent to many people at once.
 *
 * Admins can reach everyone, all users, all agents, one agent's customers, or chosen people.
 * Agents can only ever reach the customers they created (users.agent_id = agent).
 * Each recipient gets an in-app notification (category "message"); admins may also email it.
 */
class BroadcastService
{
    public const ADMIN_AUDIENCES = ['everyone', 'users', 'agents', 'agent_customers', 'selected'];
    public const AGENT_AUDIENCES = ['my_customers', 'selected'];

    private const CHUNK = 500;

    public function __construct(
        protected EmailDeliveryService $deliveryService,
        protected EmailTemplateService $templateService,
        protected UrlSecurityService $urlSecurity,
    ) {}

    /**
     * People a sender may reach for an audience. Only active accounts, never the sender.
     *
     * @param  'admin'|'agent'  $scope
     * @param  array<int, string>  $userIds
     */
    public function recipientsQuery(User $sender, string $scope, string $audience, array $userIds = [], ?string $agentId = null): Builder
    {
        $query = User::query()
            ->where('status', UserStatus::Active->value)
            ->where('id', '!=', $sender->id);

        if ($scope === 'agent') {
            // Hard boundary: an agent only ever reaches their own customers.
            $query->where('agent_id', $sender->id)->where('role', UserRole::User->value);

            return $audience === 'selected' ? $query->whereIn('id', $userIds) : $query;
        }

        return match ($audience) {
            'everyone' => $query->whereIn('role', [UserRole::User->value, UserRole::Moderator->value, UserRole::Agent->value]),
            'users' => $query->where('role', UserRole::User->value),
            'agents' => $query->where('role', UserRole::Agent->value),
            'agent_customers' => $query->where('role', UserRole::User->value)->where('agent_id', $agentId ?? '__none__'),
            'selected' => $query->whereIn('id', $userIds),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /**
     * Validate a link a message may carry: an absolute http(s) URL or a path inside the app.
     */
    public function normaliseLink(?string $link): ?string
    {
        $link = trim((string) $link);
        if ($link === '') {
            return null;
        }
        if (str_starts_with($link, '/') && ! str_starts_with($link, '//') && ! preg_match('/[\s\\\\]/', $link)) {
            return $link;
        }
        if ($this->urlSecurity->isValidHttpUrl($link)) {
            return $link;
        }

        throw ValidationException::withMessages([
            'link_url' => 'Use a full web address (https://…) or a page path such as /dashboard.',
        ]);
    }

    /**
     * Send a message and deliver it to every matching recipient.
     *
     * @param  'admin'|'agent'  $scope
     * @param  array{audience: string, user_ids?: array<int, string>, agent_id?: string|null, title: string, body: string, link_url?: string|null, send_email?: bool}  $input
     */
    public function send(User $sender, string $scope, array $input): BroadcastMessage
    {
        $audience = $input['audience'];
        $userIds = array_values(array_unique($input['user_ids'] ?? []));
        $agentId = $input['agent_id'] ?? null;
        $title = trim($input['title']);
        $body = trim($input['body']);
        $link = $this->normaliseLink($input['link_url'] ?? null);
        // Only admins may email; agents' messages stay in-app.
        $sendEmail = $scope === 'admin' && ! empty($input['send_email']);

        $query = $this->recipientsQuery($sender, $scope, $audience, $userIds, $agentId);
        if (! (clone $query)->exists()) {
            throw ValidationException::withMessages([
                'audience' => $scope === 'agent'
                    ? 'None of your customers can receive messages yet.'
                    : 'Nobody matches this audience yet.',
            ]);
        }

        $type = $scope === 'agent' ? NotificationType::AgentMessage : NotificationType::AdminMessage;
        $senderName = $scope === 'agent' ? ($sender->name ?: 'Your agent') : 'Digicardo team';
        $meta = array_filter([
            'agent_id' => $audience === 'agent_customers' ? $agentId : null,
            'user_ids' => $audience === 'selected' ? $userIds : null,
        ]);

        $broadcast = DB::transaction(function () use ($sender, $scope, $audience, $meta, $title, $body, $link, $sendEmail, $query, $type, $senderName) {
            $broadcast = BroadcastMessage::create([
                'sender_id' => $sender->id,
                'sender_role' => $scope,
                'audience' => $audience,
                'audience_meta' => $meta ?: null,
                'title' => $title,
                'body' => $body,
                'link_url' => $link,
                'recipient_count' => 0,
                'emailed' => $sendEmail,
            ]);

            $data = json_encode([
                'broadcast_id' => $broadcast->id,
                'sender_name' => $senderName,
                'sender_role' => $scope,
                'link_url' => $link,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $count = 0;
            $query->select(['id', 'name', 'email'])
                ->when($sendEmail, fn (Builder $q) => $q->with('notificationPreferences'))
                ->chunkById(self::CHUNK, function (Collection $users) use (&$count, $broadcast, $type, $title, $body, $data, $sendEmail, $link) {
                    $now = now();
                    $rows = [];
                    $ids = [];
                    foreach ($users as $user) {
                        $id = strtolower((string) Str::ulid());
                        $ids[$user->id] = $id;
                        $rows[] = [
                            'id' => $id,
                            'user_id' => $user->id,
                            'broadcast_id' => $broadcast->id,
                            'type' => $type->value,
                            'category' => NotificationCategory::Message->value,
                            'title' => $title,
                            'body' => $body,
                            'data' => $data,
                            'read_at' => null,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                    Notification::insert($rows);
                    $count += count($rows);

                    if ($sendEmail) {
                        $this->queueEmails($users, $ids, $type, $title, $body, $link);
                    }
                });

            $broadcast->update(['recipient_count' => $count]);

            return $broadcast;
        });

        return $broadcast->fresh();
    }

    /**
     * Email a chunk of recipients, respecting each person's "email me" master switch.
     *
     * @param  Collection<int, User>  $users
     * @param  array<string, string>  $notificationIds  user id => notification id
     */
    protected function queueEmails(Collection $users, array $notificationIds, NotificationType $type, string $title, string $body, ?string $link): void
    {
        $actionUrl = null;
        if ($link) {
            $actionUrl = str_starts_with($link, '/') ? rtrim((string) config('app.frontend_url', config('app.url')), '/') . $link : $link;
        }
        $html = $this->templateService->renderLayout(
            e($title),
            '<p>' . nl2br(e($body)) . '</p>',
            $actionUrl,
            $actionUrl ? 'Open' : null,
        );
        $text = "{$title}\n\n{$body}" . ($actionUrl ? "\n\n{$actionUrl}" : '') . "\n\nDigicardo";

        foreach ($users as $user) {
            $prefs = $user->notificationPreferences;
            if (($prefs && ! $prefs->email_enabled) || empty($user->email)) {
                continue;
            }
            $delivery = $this->deliveryService->createDelivery(
                recipient: $user->email,
                subject: $title . ' — Digicardo',
                type: $type->value,
                provider: config('mail.default', 'default'),
                userId: $user->id,
                notificationId: $notificationIds[$user->id] ?? null,
            );
            SendEmailJob::dispatch($delivery->id, $html, $text);
        }
    }

    /**
     * Sent messages with read counts. Admins see everything (optionally filtered), agents only their own.
     */
    public function history(?User $onlySender, ?string $senderRole = null, int $perPage = 15): LengthAwarePaginator
    {
        return BroadcastMessage::query()
            ->with('sender:id,name,email,role')
            ->withCount(['notifications as read_count' => fn ($q) => $q->whereNotNull('read_at')])
            ->when($onlySender, fn ($q) => $q->where('sender_id', $onlySender->id))
            ->when($senderRole, fn ($q) => $q->where('sender_role', $senderRole))
            ->latest('created_at')
            ->latest('id')
            ->paginate($perPage);
    }

    /**
     * Take a message back: removes it from every recipient's notifications.
     */
    public function retract(BroadcastMessage $broadcast): BroadcastMessage
    {
        DB::transaction(function () use ($broadcast) {
            Notification::where('broadcast_id', $broadcast->id)->delete();
            $broadcast->update(['retracted_at' => now()]);
        });

        return $broadcast->fresh();
    }

    /** @return array<string, mixed> */
    public function present(BroadcastMessage $b): array
    {
        $agentName = null;
        if ($b->audience === 'agent_customers' && ! empty($b->audience_meta['agent_id'])) {
            $agentName = User::whereKey($b->audience_meta['agent_id'])->value('name');
        }

        $selectedIds = $b->audience_meta['user_ids'] ?? [];
        $label = $this->audienceLabel($b->audience, count($selectedIds), $agentName);
        if ($b->audience === 'selected' && count($selectedIds) > 0 && count($selectedIds) <= 3) {
            // A few chosen people read better by name ("Aisha Rahman, Rohan Mehta").
            $names = User::whereIn('id', $selectedIds)->orderBy('name')->pluck('name')->filter()->all();
            if ($names) {
                $label = implode(', ', $names);
            }
        }

        return [
            'id' => $b->id,
            'title' => $b->title,
            'body' => $b->body,
            'link_url' => $b->link_url,
            'audience' => $b->audience,
            'audience_label' => $label,
            'sender_role' => $b->sender_role,
            'sender' => $b->sender ? ['id' => $b->sender->id, 'name' => $b->sender->name, 'email' => $b->sender->email] : null,
            'recipient_count' => $b->recipient_count,
            'read_count' => $b->retracted_at ? 0 : (int) ($b->read_count ?? 0),
            'emailed' => $b->emailed,
            'retracted_at' => $b->retracted_at?->toIso8601String(),
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }

    public function audienceLabel(string $audience, int $selected = 0, ?string $agentName = null): string
    {
        return match ($audience) {
            'everyone' => 'Everyone',
            'users' => 'All users',
            'agents' => 'All agents',
            'agent_customers' => $agentName ? "{$agentName}'s customers" : "An agent's customers",
            'my_customers' => 'All my customers',
            'selected' => $selected === 1 ? '1 person' : "{$selected} people",
            default => ucfirst(str_replace('_', ' ', $audience)),
        };
    }

    /**
     * People search for the "specific people" picker.
     *
     * @param  'admin'|'agent'  $scope
     * @return array<int, array<string, mixed>>
     */
    public function searchRecipients(User $sender, string $scope, string $search = '', ?string $role = null, int $limit = 20): array
    {
        $query = $scope === 'agent'
            ? $this->recipientsQuery($sender, 'agent', 'my_customers')
            : User::query()->where('id', '!=', $sender->id)->where('status', UserStatus::Active->value);

        if ($scope === 'admin' && in_array($role, ['user', 'agent'], true)) {
            $query->where('role', $role);
        }

        $search = trim($search);
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $query->where(function ($q) use ($like) {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhereHas('profile', fn ($p) => $p->where('username', 'like', $like));
            });
        }

        return $query->with('profile:id,user_id,username')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'email', 'role'])
            ->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->role instanceof \BackedEnum ? $u->role->value : (string) $u->role,
                'username' => $u->profile?->username,
            ])
            ->all();
    }
}
