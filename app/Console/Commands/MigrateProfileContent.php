<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Services\CardContentSchema;
use App\Services\LegacyContentMapper;
use App\Services\ProfileContentService;
use App\Services\PublicProfileCacheService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Converts legacy cards (theme_tokens.custom_options + contact blocks) into the typed
 * `profiles.content` column (ADR-030, plan §4.1).
 *
 * Safe to re-run: profiles that already have content are skipped. Run `--dry-run` first
 * and take a database snapshot before a real run in production. Only run it for real once
 * the new editor is live: the old editor still writes custom_options.
 */
class MigrateProfileContent extends Command
{
    protected $signature = 'profiles:migrate-content
        {--dry-run : Report what would change without writing}
        {--profile= : Only this profile (id or username)}
        {--allow-unmapped : Convert profiles even if they contain keys the mapper does not know}';

    protected $description = 'Convert legacy card data (custom_options) into the typed profiles.content column';

    public function handle(LegacyContentMapper $mapper, ProfileContentService $contentService, PublicProfileCacheService $cache): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $only = $this->option('profile');

        $query = Profile::query()->whereNull('content')->orderBy('id');
        if (is_string($only) && $only !== '') {
            $query->where(fn ($q) => $q->where('id', $only)->orWhere('username', strtolower($only)));
        }

        $converted = 0;
        $skipped = 0;
        $rows = [];

        $query->chunkById(100, function ($profiles) use ($mapper, $contentService, $cache, $dryRun, &$converted, &$skipped, &$rows) {
            foreach ($profiles as $profile) {
                $result = $mapper->map($profile);
                $content = $result['content'];
                $unmapped = $result['unmapped_keys'];

                $rows[] = [
                    $profile->username,
                    $profile->template_id,
                    $this->summary($content),
                    count($result['lifted_block_ids']),
                    $unmapped === [] ? '—' : implode(', ', $unmapped),
                ];

                if ($unmapped !== [] && !$this->option('allow-unmapped')) {
                    $skipped++;
                    continue;
                }

                if ($dryRun) {
                    $converted++;
                    continue;
                }

                DB::transaction(function () use ($profile, $contentService) {
                    $locked = Profile::where('id', $profile->id)->lockForUpdate()->firstOrFail();
                    if (is_array($locked->content)) {
                        return; // converted by a concurrent save
                    }
                    $contentService->store($locked, $contentService->materialize($locked));
                    $locked->version = ((int) $locked->version) + 1;
                    $locked->save();
                });
                $cache->invalidateProfile($profile->fresh() ?? $profile);
                $converted++;
            }
        });

        if ($rows !== []) {
            $this->table(['Username', 'Template', 'Content found', 'Blocks lifted', 'Unknown keys'], $rows);
        }

        $verb = $dryRun ? 'Would convert' : 'Converted';
        $this->info("{$verb} {$converted} profile(s).");
        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} profile(s) with unknown keys. Extend LegacyContentMapper, or re-run with --allow-unmapped.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function summary(array $content): string
    {
        $parts = [];
        $filledContact = count(array_filter($content['contact'], fn ($v) => $v !== ''));
        if ($filledContact > 0) {
            $parts[] = "{$filledContact} contact";
        }
        foreach (['socials' => 'social', 'links' => 'links', 'documents' => 'docs'] as $key => $label) {
            if (($n = count($content[$key])) > 0) {
                $parts[] = "{$n} {$label}";
            }
        }
        if (($n = count($content['catalog']['services']) + count($content['catalog']['products'])) > 0) {
            $parts[] = "{$n} catalog";
        }
        if ($content['payments']['upi_id'] !== '' || $content['payments']['bank']['account_number'] !== '') {
            $parts[] = 'payments';
        }
        if (($n = count($content['gallery']['images'])) > 0) {
            $parts[] = "{$n} photos";
        }

        return $parts === [] ? 'name only' : implode(' · ', $parts);
    }
}
