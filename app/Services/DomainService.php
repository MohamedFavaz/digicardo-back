<?php

namespace App\Services;

use App\Contracts\CustomHostnameProviderInterface;
use App\Contracts\DnsVerificationServiceInterface;
use App\Enums\DomainStatus;
use App\Exceptions\ConflictException;
use App\Models\Profile;
use App\Models\ProfileDomain;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DomainService
{
    /**
     * System-reserved domains that cannot be claimed as custom domains.
     */
    private const SYSTEM_DOMAINS = [
        'Digicardo.app',
        'www.Digicardo.app',
        'app.Digicardo.app',
        'api.Digicardo.app',
        'localhost',
        '127.0.0.1',
    ];

    public function __construct(
        private readonly DnsVerificationServiceInterface $dnsVerifier,
        private readonly CustomHostnameProviderInterface $hostnameProvider,
        private readonly FeatureEntitlementService $entitlementService,
        private readonly ?NotificationService $notificationService = null,
        private readonly ?\App\Services\Email\EmailTemplateService $templateService = null
    ) {
    }

    /**
     * Normalize a raw domain string.
     * Trims whitespace, converts to lowercase, and removes trailing dots.
     */
    public function normalizeDomain(string $rawDomain): string
    {
        $domain = trim($rawDomain);
        $domain = strtolower($domain);
        $domain = rtrim($domain, '.');

        return $domain;
    }

    /**
     * Validate a domain for syntax and security restrictions.
     *
     * @throws ValidationException
     */
    public function validateDomain(string $rawDomain): string
    {
        $domain = $this->normalizeDomain($rawDomain);

        if (empty($domain)) {
            throw ValidationException::withMessages([
                'domain' => ['The domain field is required.'],
            ]);
        }

        // 1. Reject protocols
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $rawDomain) || str_contains($rawDomain, '://')) {
            throw ValidationException::withMessages([
                'domain' => ['Domain must not contain URL protocols (e.g. http:// or https://).'],
            ]);
        }

        // 2. Reject paths and slashes
        if (str_contains($rawDomain, '/') || str_contains($rawDomain, '\\')) {
            throw ValidationException::withMessages([
                'domain' => ['Domain must not contain URL paths or slashes.'],
            ]);
        }

        // 3. Reject userinfo / authority confusion (@ character)
        if (str_contains($rawDomain, '@')) {
            throw ValidationException::withMessages([
                'domain' => ['Domain contains invalid characters.'],
            ]);
        }

        // 4. Reject ports
        if (str_contains($domain, ':')) {
            throw ValidationException::withMessages([
                'domain' => ['Domain must not include port numbers.'],
            ]);
        }

        // 5. Reject wildcards
        if (str_contains($domain, '*')) {
            throw ValidationException::withMessages([
                'domain' => ['Wildcard domains are not permitted.'],
            ]);
        }

        // 6. Reject IP addresses (IPv4 / IPv6)
        if (filter_var($domain, FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages([
                'domain' => ['IP addresses cannot be used as custom domains.'],
            ]);
        }

        // 7. Reject localhost and local subdomains
        if ($domain === 'localhost' || str_ends_with($domain, '.localhost') || str_ends_with($domain, '.local')) {
            throw ValidationException::withMessages([
                'domain' => ['Localhost and local domains cannot be registered.'],
            ]);
        }

        // 8. Reject Digicardo system domains
        foreach (self::SYSTEM_DOMAINS as $sysDomain) {
            if ($domain === $sysDomain || str_ends_with($domain, '.' . $sysDomain)) {
                throw ValidationException::withMessages([
                    'domain' => ['System domain cannot be registered as a custom domain.'],
                ]);
            }
        }

        // 9. Validate hostname structure according to RFC 1035 / 1123
        if (strlen($domain) > 253) {
            throw ValidationException::withMessages([
                'domain' => ['Domain name exceeds maximum allowed length of 253 characters.'],
            ]);
        }

        $labels = explode('.', $domain);
        if (count($labels) < 2) {
            throw ValidationException::withMessages([
                'domain' => ['Domain must include a valid top-level domain (TLD).'],
            ]);
        }

        foreach ($labels as $label) {
            $len = strlen($label);
            if ($len < 1 || $len > 63) {
                throw ValidationException::withMessages([
                    'domain' => ['Domain labels must be between 1 and 63 characters.'],
                ]);
            }

            if (!preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                throw ValidationException::withMessages([
                    'domain' => ['Domain labels can only contain letters, numbers, and hyphens (cannot start or end with a hyphen).'],
                ]);
            }
        }

        // TLD cannot be purely numeric
        $tld = end($labels);
        if (preg_match('/^[0-9]+$/', $tld)) {
            throw ValidationException::withMessages([
                'domain' => ['Top-level domain cannot be purely numeric.'],
            ]);
        }

        return $domain;
    }

    /**
     * Create a new custom domain entry in pending state.
     *
     * @throws ValidationException|ConflictException
     */
    public function createDomain(Profile $profile, User $user, string $rawDomain): ProfileDomain
    {
        $normalized = $this->validateDomain($rawDomain);

        // Check if domain is already registered
        $existing = ProfileDomain::where('normalized_domain', $normalized)->first();
        if ($existing) {
            if ($existing->profile_id === $profile->id) {
                throw new ConflictException('This domain is already registered to your profile.');
            }
            throw new ConflictException('This domain is already registered to another profile.');
        }

        // Enforce plan custom domain limit
        $this->entitlementService->assertWithinLimit($user, \App\Enums\FeatureKey::CustomDomainCount);

        $token = bin2hex(random_bytes(16)); // 32 chars high-entropy

        return ProfileDomain::create([
            'id' => (string) Str::ulid(),
            'profile_id' => $profile->id,
            'user_id' => $user->id,
            'domain' => trim($rawDomain),
            'normalized_domain' => $normalized,
            'status' => DomainStatus::Pending,
            'verification_method' => 'txt_record',
            'verification_token' => $token,
            'is_primary' => false,
            'ssl_status' => 'pending',
        ]);
    }

    /**
     * Perform DNS TXT verification for a domain.
     */
    public function verifyDomain(ProfileDomain $domain): ProfileDomain
    {
        $recordHost = $domain->getVerificationHost();
        $expectedToken = $domain->verification_token;

        $domain->update([
            'status' => DomainStatus::Verifying,
            'last_checked_at' => now(),
        ]);

        $verified = $this->dnsVerifier->verifyToken($recordHost, $expectedToken);

        if ($verified) {
            $domain->update([
                'status' => DomainStatus::Verified,
                'verified_at' => now(),
                'failure_reason' => null,
            ]);

            // Attempt edge hostname provisioning
            $provisionResult = $this->hostnameProvider->provisionCustomHostname($domain->normalized_domain);
            if ($provisionResult['success']) {
                $domain->update([
                    'ssl_status' => $provisionResult['ssl_status'] ?? 'pending',
                ]);
            }

            // Dispatch domain verified notification
            $user = $domain->user;
            if ($user) {
                try {
                    $notifSvc = $this->notificationService ?? app(NotificationService::class);
                    $tmplSvc = $this->templateService ?? app(\App\Services\Email\EmailTemplateService::class);
                    $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');
                    $domainsUrl = "{$appUrl}/dashboard/domains";
                    $tmpl = $tmplSvc->renderDomainVerified($user->name ?: $user->email, $domain->domain, $domainsUrl);

                    $notifSvc->createForUser(
                        user: $user,
                        type: \App\Enums\NotificationType::DomainVerified,
                        category: \App\Enums\NotificationCategory::Domain,
                        title: "Domain Verified: {$domain->domain}",
                        body: "Your custom domain {$domain->domain} has been successfully verified and is ready to route traffic.",
                        data: ['domain_id' => $domain->id, 'domain' => $domain->domain],
                        sendEmail: true,
                        customSubject: $tmpl['subject'],
                        emailHtml: $tmpl['html'],
                        emailText: $tmpl['text']
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[DomainService] Failed to dispatch domain verified notification', ['error' => $e->getMessage()]);
                }
            }
        } else {
            $domain->update([
                'status' => DomainStatus::Failed,
                'failure_reason' => "DNS TXT record not found at {$recordHost}. Please ensure the TXT record contains 'Digicardo-verification={$expectedToken}' and DNS has propagated.",
            ]);

            // Dispatch domain failed notification
            $user = $domain->user;
            if ($user) {
                try {
                    $notifSvc = $this->notificationService ?? app(NotificationService::class);
                    $tmplSvc = $this->templateService ?? app(\App\Services\Email\EmailTemplateService::class);
                    $appUrl = rtrim(config('app.frontend_url', config('app.url', 'https://Digicardo.app')), '/');
                    $domainsUrl = "{$appUrl}/dashboard/domains";
                    $tmpl = $tmplSvc->renderDomainFailed($user->name ?: $user->email, $domain->domain, $domainsUrl);

                    $notifSvc->createForUser(
                        user: $user,
                        type: \App\Enums\NotificationType::DomainFailed,
                        category: \App\Enums\NotificationCategory::Domain,
                        title: "Domain Verification Issue: {$domain->domain}",
                        body: "We could not verify DNS records for {$domain->domain}. Please verify your TXT records.",
                        data: ['domain_id' => $domain->id, 'domain' => $domain->domain],
                        sendEmail: true,
                        customSubject: $tmpl['subject'],
                        emailHtml: $tmpl['html'],
                        emailText: $tmpl['text']
                    );
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('[DomainService] Failed to dispatch domain failed notification', ['error' => $e->getMessage()]);
                }
            }
        }

        return $domain->fresh();
    }

    /**
     * Activate a verified domain for public traffic routing.
     *
     * @throws ValidationException
     */
    public function activateDomain(ProfileDomain $domain): ProfileDomain
    {
        if (!$domain->status->isVerified()) {
            throw ValidationException::withMessages([
                'domain' => ['Domain must be verified before it can be activated.'],
            ]);
        }

        // Check if profile has any other active primary domain
        $hasActivePrimary = ProfileDomain::where('profile_id', $domain->profile_id)
            ->where('is_primary', true)
            ->where('status', DomainStatus::Active)
            ->exists();

        $domain->update([
            'status' => DomainStatus::Active,
            'activated_at' => now(),
            'is_primary' => !$hasActivePrimary, // Make primary if first active domain
            'failure_reason' => null,
        ]);

        $this->invalidateCache($domain->normalized_domain);

        return $domain->fresh();
    }

    /**
     * Set domain as the primary routing domain for its profile.
     *
     * @throws ValidationException
     */
    public function setPrimaryDomain(ProfileDomain $domain): ProfileDomain
    {
        if ($domain->status !== DomainStatus::Active) {
            throw ValidationException::withMessages([
                'domain' => ['Only active domains can be set as primary.'],
            ]);
        }

        DB::transaction(function () use ($domain) {
            // Unset all other domains for this profile
            ProfileDomain::where('profile_id', $domain->profile_id)
                ->where('id', '!=', $domain->id)
                ->update(['is_primary' => false]);

            $domain->update(['is_primary' => true]);
        });

        $this->invalidateCache($domain->normalized_domain);

        return $domain->fresh();
    }

    /**
     * Disable an active domain.
     */
    public function disableDomain(ProfileDomain $domain): ProfileDomain
    {
        $domain->update([
            'status' => DomainStatus::Disabled,
            'is_primary' => false,
        ]);

        $this->invalidateCache($domain->normalized_domain);

        return $domain->fresh();
    }

    /**
     * Remove and delete a custom domain safely.
     */
    public function deleteDomain(ProfileDomain $domain): bool
    {
        $normalized = $domain->normalized_domain;

        $this->invalidateCache($normalized);
        $this->hostnameProvider->deleteCustomHostname($normalized);

        return (bool) $domain->delete();
    }

    /**
     * Resolve a public incoming Host to an active profile.
     * Uses controlled caching to ensure sub-millisecond edge lookups.
     *
     * @return array{profile_id: string, username: string, display_name: string}|null
     */
    public function resolveHost(string $rawHost): ?array
    {
        // Strip optional port from Host header (e.g. "john.com:443" -> "john.com")
        $host = explode(':', $rawHost)[0];
        $normalized = $this->normalizeDomain($host);

        // Disallow system domains from custom domain lookup
        foreach (self::SYSTEM_DOMAINS as $sysDomain) {
            if ($normalized === $sysDomain || str_ends_with($normalized, '.' . $sysDomain)) {
                return null;
            }
        }

        // Read through cache (5 minute TTL)
        $cacheKey = "domain:{$normalized}";

        return Cache::remember($cacheKey, 300, function () use ($normalized) {
            $domain = ProfileDomain::where('normalized_domain', $normalized)
                ->where('status', DomainStatus::Active)
                ->with('profile')
                ->first();

            if (!$domain || !$domain->profile || !$domain->profile->is_public) {
                return null;
            }

            return [
                'profile_id' => $domain->profile->id,
                'username' => $domain->profile->username,
                'display_name' => $domain->profile->display_name,
            ];
        });
    }

    /**
     * Invalidate the cached resolution entry for a domain.
     */
    public function invalidateCache(string $normalizedDomain): void
    {
        Cache::forget("domain:{$normalizedDomain}");
    }
}
