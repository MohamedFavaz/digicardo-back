<?php

namespace App\Services;

use App\Jobs\RevalidatePartnerPage;
use App\Contracts\AgentDomainDnsCheckerInterface;
use App\Contracts\CustomHostnameProviderInterface;
use App\Enums\UserRole;
use App\Models\AgentSite;
use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent sites: landing pages, the cards an agent shows off, and admin-managed agent domains.
 *
 * Card links: a customer created by an agent with an active domain lives at
 * https://{agent-domain}/{username}; everyone else at {frontend}/{username}.
 */
class AgentSiteService
{
    private const RESERVED_SLUGS = ['admin', 'agent', 'agents', 'api', 'partner', 'partners', 'login', 'dashboard', 'digicardo', 'www', 'new', 'edit', 'settings'];

    public function __construct(
        private readonly DomainService $domains,
        private readonly AgentDomainDnsCheckerInterface $dns,
        private readonly CustomHostnameProviderInterface $hostnames,
    ) {}

    // ── Site ────────────────────────────────────────────────────────────────────

    public function forAgent(User $agent): AgentSite
    {
        $site = AgentSite::where('agent_id', $agent->id)->first();
        if ($site) {
            return $site;
        }

        return AgentSite::create([
            'agent_id' => $agent->id,
            'slug' => $this->uniqueSlug($agent->name ?: 'partner'),
            'content' => AgentSiteContent::normalize(null, $agent, fn () => false),
            'published' => false,
        ]);
    }

    public function uniqueSlug(string $base, ?string $ignoreSiteId = null): string
    {
        $slug = Str::slug($base) ?: 'partner';
        $slug = substr($slug, 0, 40);
        if (in_array($slug, self::RESERVED_SLUGS, true) || strlen($slug) < 3) {
            $slug = substr($slug . '-cards', 0, 40);
        }
        $candidate = $slug;
        $i = 2;
        while (AgentSite::where('slug', $candidate)->when($ignoreSiteId, fn ($q) => $q->where('id', '!=', $ignoreSiteId))->exists()) {
            $candidate = substr($slug, 0, 36) . '-' . $i++;
        }

        return $candidate;
    }

    public function validateSlug(string $slug, AgentSite $site): string
    {
        $slug = strtolower(trim($slug));
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]{1,38}[a-z0-9])$/', $slug)) {
            throw ValidationException::withMessages(['slug' => 'Use 3–40 lowercase letters, numbers or dashes (no dash at the start or end).']);
        }
        if (in_array($slug, self::RESERVED_SLUGS, true)) {
            throw ValidationException::withMessages(['slug' => 'That address is reserved. Try another.']);
        }
        if (AgentSite::where('slug', $slug)->where('id', '!=', $site->id)->exists()) {
            throw ValidationException::withMessages(['slug' => 'That address is already taken.']);
        }

        return $slug;
    }

    /** Only images the agent uploaded for their site may be used on it. */
    public function imageAllowed(string $agentId): callable
    {
        return fn (string $url): bool => str_contains($url, "/storage/agent-sites/{$agentId}/");
    }

    /**
     * @param  array<string, mixed>  $content
     */
    public function updateContent(AgentSite $site, array $content, ?string $slug = null): AgentSite
    {
        $agent = $site->agent;
        $normalized = AgentSiteContent::normalize($content, $agent, $this->imageAllowed($site->agent_id));
        // Showcase may only list this agent's own customers' cards.
        $owned = $this->customerProfiles($site->agent_id)->pluck('id')->all();
        $normalized['showcase']['profile_ids'] = array_values(array_intersect($normalized['showcase']['profile_ids'], $owned));

        $oldSlug = $site->slug;
        $oldBrand = $site->content['brand']['name'] ?? null;
        $site->content = $normalized;
        if ($slug !== null && $slug !== $site->slug) {
            $site->slug = $this->validateSlug($slug, $site);
        }
        $site->save();
        // Cards show "Card by {brand}" linking to this page: rebuild them only when that changes.
        $brandChanged = $oldBrand !== ($normalized['brand']['name'] ?? null) || $oldSlug !== $site->slug;
        $this->forgetCaches($site, null, $brandChanged, $oldSlug);

        return $site->fresh();
    }

    public function setPublished(AgentSite $site, bool $published): AgentSite
    {
        $site->published = $published;
        $site->published_at = $published ? ($site->published_at ?? now()) : $site->published_at;
        $site->save();
        $this->forgetCaches($site, null, true);

        return $site->fresh();
    }

    public function uploadImage(User $agent, UploadedFile $file): string
    {
        $ext = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'jpg');
        $path = $file->storeAs("agent-sites/{$agent->id}", strtolower((string) Str::ulid()) . '.' . $ext, 'public');

        return Storage::disk('public')->url($path);
    }

    // ── Cards ──────────────────────────────────────────────────────────────────

    /** Profiles belonging to customers this agent created. */
    public function customerProfiles(string $agentId)
    {
        return Profile::query()
            ->whereHas('user', fn ($q) => $q->where('agent_id', $agentId)->where('role', UserRole::User->value))
            ->orderByDesc('created_at')
            ->get();
    }

    /** @return array<string, mixed> */
    public function cardSummary(Profile $profile, ?string $domain = null): array
    {
        $content = is_array($profile->content) ? $profile->content : [];
        $identity = is_array($content['identity'] ?? null) ? $content['identity'] : [];
        $cover = is_array($identity['cover_images'] ?? null) ? ($identity['cover_images'][0] ?? null) : null;

        return [
            'id' => $profile->id,
            'username' => $profile->username,
            'name' => $identity['name'] ?? $profile->display_name ?? $profile->username,
            'role' => trim(implode(' · ', array_filter([$identity['role'] ?? '', $identity['company'] ?? '']))),
            'avatar_url' => $identity['avatar_url'] ?? $profile->avatar_url,
            'cover_url' => $cover ?: null,
            'template_id' => $profile->template_id,
            'is_public' => (bool) $profile->is_public,
            'url' => $this->cardUrlFor($profile->username, $domain),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function showcaseCards(AgentSite $site): array
    {
        $ids = $site->content['showcase']['profile_ids'] ?? [];
        if (! $ids) {
            return [];
        }
        $domain = $site->hasActiveDomain() ? $site->domain : null;
        $profiles = Profile::whereIn('id', $ids)
            ->where('is_public', true)
            ->whereHas('user', fn ($q) => $q->where('agent_id', $site->agent_id))
            ->get()
            ->keyBy('id');

        return array_values(array_filter(array_map(fn ($id) => isset($profiles[$id]) ? $this->cardSummary($profiles[$id], $domain) : null, $ids)));
    }

    // ── Links ──────────────────────────────────────────────────────────────────

    private function frontendBase(): string
    {
        return rtrim((string) config('app.frontend_url', config('app.url')), '/');
    }

    public function cardUrlFor(string $username, ?string $agentDomain): string
    {
        return $agentDomain ? "https://{$agentDomain}/{$username}" : $this->frontendBase() . "/{$username}";
    }

    public function landingUrl(AgentSite $site): string
    {
        return $site->hasActiveDomain() ? "https://{$site->domain}" : $this->frontendBase() . "/partner/{$site->slug}";
    }

    /** Active domain of the agent who created this user (null for everyone else). Cached. */
    public function activeDomainForUser(?User $owner): ?string
    {
        if (! $owner || ! $owner->agent_id) {
            return null;
        }

        return Cache::remember("agent-domain:{$owner->agent_id}", 300, function () use ($owner) {
            $site = AgentSite::where('agent_id', $owner->agent_id)->first();

            return $site && $site->hasActiveDomain() ? $site->domain : null;
        }) ?: null;
    }

    /** Agent brand shown on cards instead of "Made with Digicardo". @return array{name: string, url: string}|null */
    public function brandForUser(?User $owner): ?array
    {
        if (! $owner || ! $owner->agent_id) {
            return null;
        }

        return Cache::remember("agent-brand:{$owner->agent_id}", 300, function () use ($owner) {
            $site = AgentSite::where('agent_id', $owner->agent_id)->first();
            if (! $site || ! $site->isLandingLive()) {
                return null;
            }

            return ['name' => $site->content['brand']['name'] ?? 'Partner', 'url' => $this->landingUrl($site)];
        }) ?: null;
    }

    // ── Public ─────────────────────────────────────────────────────────────────

    /** @return array<string, mixed>|null */
    public function publicPayload(string $slug): ?array
    {
        $site = AgentSite::where('slug', strtolower($slug))->first();
        if (! $site || ! $site->isLandingLive()) {
            return null;
        }

        return [
            'slug' => $site->slug,
            'domain' => $site->hasActiveDomain() ? $site->domain : null,
            'page_url' => $this->landingUrl($site),
            'content' => AgentSiteContent::normalize($site->content, $site->agent, $this->imageAllowed($site->agent_id)),
            'showcase' => $this->showcaseCards($site),
        ];
    }

    /** Host → agent site, for the frontend's domain routing. @return array<string, mixed>|null */
    public function resolveHost(string $host): ?array
    {
        $host = strtolower(rtrim(explode(':', trim($host))[0], '.'));

        return Cache::remember("agent-host:{$host}", 300, function () use ($host) {
            $site = AgentSite::where('domain', $host)->where('domain_status', AgentSite::DOMAIN_ACTIVE)->first();

            return $site ? ['type' => 'agent', 'slug' => $site->slug, 'domain' => $site->domain, 'landing_live' => $site->isLandingLive()] : null;
        }) ?: null;
    }

    // ── Domains (admin only) ───────────────────────────────────────────────────

    public function setDomain(AgentSite $site, ?string $raw): AgentSite
    {
        $old = $site->domain;
        if ($raw === null || trim($raw) === '') {
            return $this->removeDomain($site);
        }

        $domain = $this->domains->validateDomain($raw);
        if (AgentSite::where('domain', $domain)->where('id', '!=', $site->id)->exists()
            || ProfileDomain::where('normalized_domain', $domain)->exists()) {
            throw ValidationException::withMessages(['domain' => 'This domain is already used by another card or agent.']);
        }

        if ($old && $old !== $domain) {
            $this->safelyDeleteHostname($old);
        }

        $site->fill([
            'domain' => $domain,
            'domain_status' => $old === $domain ? $site->domain_status : AgentSite::DOMAIN_PENDING,
            'domain_error' => null,
            'domain_activated_at' => $old === $domain ? $site->domain_activated_at : null,
        ])->save();
        $this->forgetCaches($site, $old, true);

        return $site->fresh();
    }

    /** Check DNS; activate when it points at us. */
    public function checkDomain(AgentSite $site): AgentSite
    {
        if (! $site->domain) {
            throw ValidationException::withMessages(['domain' => 'Add a domain first.']);
        }
        $result = $this->dns->check($site->domain);
        $site->domain_checked_at = now();
        $wasActive = $site->hasActiveDomain();

        if ($result['ok']) {
            return $this->activate($site);
        }

        $site->domain_status = AgentSite::DOMAIN_FAILED;
        $site->domain_error = $result['expected'] === []
            ? 'DNS target is not configured on the server (AGENT_DOMAIN_A_RECORDS / AGENT_DOMAIN_CNAME_TARGET).'
            : ($result['found'] === []
                ? 'The domain does not point anywhere yet. DNS changes can take up to an hour.'
                : 'The domain points to ' . implode(', ', $result['found']) . ' instead of ' . implode(' or ', $result['expected']) . '.');
        $site->save();
        $this->forgetCaches($site, null, $wasActive);

        return $site->fresh();
    }

    /** Make the domain live (after a passing check, or an admin override). */
    public function activate(AgentSite $site): AgentSite
    {
        if (! $site->domain) {
            throw ValidationException::withMessages(['domain' => 'Add a domain first.']);
        }
        try {
            $this->hostnames->provisionCustomHostname($site->domain);
        } catch (\Throwable $e) {
            Log::warning('[AgentSite] Hostname provisioning failed', ['domain' => $site->domain, 'error' => $e->getMessage()]);
        }
        $site->fill([
            'domain_status' => AgentSite::DOMAIN_ACTIVE,
            'domain_error' => null,
            'domain_activated_at' => now(),
            'domain_checked_at' => $site->domain_checked_at ?? now(),
        ])->save();
        $this->forgetCaches($site, null, true);

        return $site->fresh();
    }

    public function removeDomain(AgentSite $site): AgentSite
    {
        $old = $site->domain;
        if ($old) {
            $this->safelyDeleteHostname($old);
        }
        $site->fill(['domain' => null, 'domain_status' => null, 'domain_error' => null, 'domain_checked_at' => null, 'domain_activated_at' => null])->save();
        $this->forgetCaches($site, $old, true);

        return $site->fresh();
    }

    public function setLandingDisabled(AgentSite $site, bool $disabled): AgentSite
    {
        $site->landing_disabled = $disabled;
        $site->save();
        $this->forgetCaches($site, null, true);

        return $site->fresh();
    }

    /** @return array<string, mixed> */
    public function dnsInstructions(): array
    {
        return [
            'a_records' => (array) config('agent_sites.a_records', []),
            'cname_target' => (string) config('agent_sites.cname_target', ''),
        ];
    }

    private function safelyDeleteHostname(string $domain): void
    {
        try {
            $this->hostnames->deleteCustomHostname($domain);
        } catch (\Throwable $e) {
            Log::warning('[AgentSite] Hostname removal failed', ['domain' => $domain, 'error' => $e->getMessage()]);
        }
    }

    /**
     * Drops the cached domain / brand / host lookups, then has the frontend rebuild the landing
     * page (and the old address after a rename). With $refreshCards, every customer card of this
     * agent is rebuilt too — their QR code, share link and "Card by" footer depend on the domain,
     * the brand and whether the page is live. Caches go first so the rebuilt pages read fresh data.
     */
    private function forgetCaches(AgentSite $site, ?string $oldDomain = null, bool $refreshCards = false, ?string $oldSlug = null): void
    {
        Cache::forget("agent-domain:{$site->agent_id}");
        Cache::forget("agent-brand:{$site->agent_id}");
        foreach (array_filter([$site->domain, $oldDomain]) as $d) {
            Cache::forget("agent-host:{$d}");
        }

        foreach (array_unique(array_filter([$site->slug, $oldSlug])) as $slug) {
            RevalidatePartnerPage::dispatchAfterResponse($slug);
        }
        if ($refreshCards) {
            $profiles = app(PublicProfileCacheService::class);
            foreach ($this->customerProfiles($site->agent_id) as $profile) {
                $profiles->invalidateProfile($profile);
            }
        }
    }

    // ── Presentation ───────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    public function presentForOwner(AgentSite $site): array
    {
        return [
            'id' => $site->id,
            'slug' => $site->slug,
            'content' => AgentSiteContent::normalize($site->content, $site->agent, $this->imageAllowed($site->agent_id)),
            'published' => $site->published,
            'published_at' => $site->published_at?->toIso8601String(),
            'landing_disabled' => $site->landing_disabled,
            'page_url' => $this->landingUrl($site),
            'default_url' => $this->frontendBase() . "/partner/{$site->slug}",
            'domain' => $site->domain,
            'domain_status' => $site->domain_status,
            'domain_error' => $site->domain_error,
            'domain_checked_at' => $site->domain_checked_at?->toIso8601String(),
            'domain_activated_at' => $site->domain_activated_at?->toIso8601String(),
            'updated_at' => $site->updated_at?->toIso8601String(),
        ];
    }
}
