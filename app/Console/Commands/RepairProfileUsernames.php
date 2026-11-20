<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\Profile;
use App\Services\PublicProfileCacheService;
use App\Services\UsernameService;
use Illuminate\Console\Command;

/**
 * Repairs card addresses left behind by older builds. Lists only by default; `--fix` applies.
 * Nothing is deleted, and nothing that works today is changed:
 *
 * - Renamed: addresses that can never open — not a valid username ("ravi agent", spaces etc.,
 *   which the public page refuses) or the same as a page of the site (e.g. /login). Their QR/NFC
 *   links never worked, so renaming breaks nothing.
 * - Only listed: other reserved names that still open today (e.g. /help) — renaming would break
 *   printed QR codes, so that is left to the owner.
 * - Hidden: empty public cards auto-created for agent accounts (no content, photo or blocks).
 *   Agent cards with real content are only listed.
 */
class RepairProfileUsernames extends Command
{
    /** Top-level pages of the frontend (src/app): a card with one of these names can never open. */
    public const SITE_PAGES = [
        'account-deleted', 'admin', 'agent', 'agent-login', 'api', 'dashboard', 'forgot-password',
        'internal-admin-sign-in', 'login', 'partner', 'partner-preview', 'register', 'reset-password',
        'setup', 'storage', 'verify-email',
    ];

    protected $signature = 'profiles:repair-usernames {--fix : Apply the repairs (otherwise only list them)}';

    protected $description = 'List (or with --fix, repair) card addresses that can never open, and empty agent cards';

    public function handle(PublicProfileCacheService $cache): int
    {
        $fix = (bool) $this->option('fix');
        $profiles = Profile::with('user:id,name,email,role')->withCount('blocks')->get();
        $roleOf = fn (Profile $p) => $p->user?->role instanceof UserRole ? $p->user->role->value : (string) $p->user?->role;
        $name = fn (Profile $p) => strtolower(trim((string) $p->username));

        $broken = $profiles->filter(fn (Profile $p) => ! UsernameService::isValidFormat($name($p)) || in_array($name($p), self::SITE_PAGES, true));
        $reservedButWorking = $profiles->filter(fn (Profile $p) => UsernameService::isValidFormat($name($p))
            && UsernameService::isReserved($name($p)) && ! in_array($name($p), self::SITE_PAGES, true));
        $agentCards = $profiles->filter(fn (Profile $p) => $roleOf($p) === UserRole::Agent->value && $p->is_public);
        $emptyAgentCards = $agentCards->filter(fn (Profile $p) => $p->content === null && $p->blocks_count === 0 && ! $p->avatar_url);
        $usedAgentCards = $agentCards->diffKeys($emptyAgentCards);

        // ── Addresses that can never open: rename ────────────────────────────────────
        if ($broken->isNotEmpty()) {
            $taken = $profiles->map($name)->flip()->all();
            $rows = [];
            foreach ($broken as $profile) {
                $base = UsernameService::fromName((string) ($profile->username ?: $profile->user?->name ?: 'user'));
                $candidate = $base;
                for ($n = 2; isset($taken[$candidate]) || UsernameService::isReserved($candidate); $n++) {
                    $suffix = '-' . $n;
                    $candidate = rtrim(substr($base, 0, 30 - strlen($suffix)), '-') . $suffix;
                }
                $taken[$candidate] = true;
                $rows[] = [$profile->username, $candidate, $roleOf($profile), $profile->user?->email];

                if ($fix) {
                    $old = (string) $profile->username;
                    $profile->username = $candidate;
                    $profile->save();
                    $cache->invalidateProfile($old);
                    $cache->invalidateProfile($profile);
                }
            }
            $this->info('Addresses that can never open (renaming breaks nothing):');
            $this->table(['Current address', $fix ? 'Renamed to' : 'Would become', 'Owner role', 'Owner email'], $rows);
        } else {
            $this->info('No card addresses that can never open.');
        }

        // ── Reserved names that still open: list only ────────────────────────────────
        if ($reservedButWorking->isNotEmpty()) {
            $this->warn('Reserved names that still open today — left as they are (renaming would break printed QR/NFC links):');
            $this->table(['Address', 'Owner email'], $reservedButWorking->map(fn (Profile $p) => [$p->username, $p->user?->email])->values()->all());
        }

        // ── Agent-owned public cards ─────────────────────────────────────────────────
        if ($emptyAgentCards->isNotEmpty()) {
            $this->info('Empty cards auto-created for agent accounts' . ($fix ? ' (now hidden):' : ' (--fix hides them):'));
            $this->table(['Address', 'Agent email'], $emptyAgentCards->map(fn (Profile $p) => [$p->username, $p->user?->email])->values()->all());
            if ($fix) {
                foreach ($emptyAgentCards as $profile) {
                    $profile->is_public = false;
                    $profile->save();
                    $cache->invalidateProfile($profile);
                }
            }
        }
        if ($usedAgentCards->isNotEmpty()) {
            $this->warn('Agent accounts with a card that has content — left as they are:');
            $this->table(['Address', 'Agent email'], $usedAgentCards->map(fn (Profile $p) => [$p->username, $p->user?->email])->values()->all());
        }

        if (! $fix && ($broken->isNotEmpty() || $emptyAgentCards->isNotEmpty())) {
            $this->line('Nothing changed. Review the lists above, then run again with --fix.');
        }

        return self::SUCCESS;
    }
}
