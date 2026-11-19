<?php

namespace Kraenkvisuell\StatamicBunnyCache\Console;

use Illuminate\Console\Command;
use Kraenkvisuell\StatamicBunnyCache\BunnyApi;
use Statamic\Console\RunsInPlease;
use Throwable;

class CheckCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:bunny:check
        {--fix : Stop the pull zone from stripping cookies}';

    protected $description = 'Check that the bunny.net pull zone and Statamic are set up for edge caching';

    private bool $failed = false;

    public function handle(BunnyApi $api): int
    {
        if (config('statamic.static_caching.strategy') !== 'bunny') {
            $this->components->warn('STATAMIC_STATIC_CACHING_STRATEGY is not "bunny", so pages are not cached at bunny.net.');
        }

        if (! $api->isConfigured()) {
            $this->components->error('Set BUNNY_API_KEY and BUNNY_PULL_ZONE_ID first.');

            return self::FAILURE;
        }

        foreach ($api->pullZoneIds() as $id) {
            try {
                $this->checkZone($api, $id);
            } catch (Throwable $e) {
                $this->components->error("Pull zone {$id}: ".$e->getMessage());
                $this->failed = true;
            }
        }

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkZone(BunnyApi $api, string $id): void
    {
        $zone = $api->pullZone($id);

        $this->components->twoColumnDetail('<fg=cyan>Pull zone '.$id.'</>', (string) ($zone['Name'] ?? ''));

        if ($zone['DisableCookies'] ?? false) {
            if ($this->option('fix')) {
                $api->updatePullZone($id, ['DisableCookies' => false]);
                $this->passes('Cookie stripping', 'turned off');
            } else {
                $this->fails('Cookie stripping', 'on: logins and other sessions break (run with --fix)');
            }
        } else {
            $this->passes('Cookie stripping', 'off');
        }

        ($zone['EnableSmartCache'] ?? false)
            ? $this->fails('Smart Cache', 'on: it never caches HTML, so no page is cached; turn it off')
            : $this->passes('Smart Cache', 'off');

        $override = (int) ($zone['CacheControlMaxAgeOverride'] ?? -1);

        $override === -1
            ? $this->passes('Cache expiration time', 'respects the origin')
            : $this->fails('Cache expiration time', "overridden ({$override} s): set it to \"Respect origin Cache-Control\", or the edge also caches the CP and other private pages");

        (int) ($zone['PermaCacheStorageZoneId'] ?? 0) === 0
            ? $this->passes('Perma-Cache', 'off')
            : $this->fails('Perma-Cache', 'on: bunny.net cannot purge by tag with Perma-Cache, turn it off');

        $statamicIgnores = (bool) config('statamic.static_caching.ignore_query_strings');
        $bunnyIgnores = (bool) ($zone['IgnoreQueryStrings'] ?? false);

        if ($statamicIgnores && ! $bunnyIgnores) {
            $this->warns('Query strings', 'Statamic ignores them but bunny.net caches every variant; turn on "Ignore query strings" (Vary by query string off) in the pull zone');
        } elseif (! $statamicIgnores && $bunnyIgnores) {
            $this->warns('Query strings', 'bunny.net ignores them but Statamic does not; pages that depend on a query string are served from one cached copy');
        } else {
            $this->passes('Query strings', $statamicIgnores ? 'ignored on both sides' : 'vary on both sides');
        }

        ($zone['EnableRequestCoalescing'] ?? false)
            ? $this->fails('Request coalescing', 'on: simultaneous requests for an uncached private page (e.g. the CP) can get each other\'s response; turn it off')
            : $this->passes('Request coalescing', 'off');

        ($zone['EnableOriginShield'] ?? false)
            ? $this->passes('Origin Shield', 'on')
            : $this->warns('Origin Shield', 'off: every bunny.net location renders its own copy from the origin, and static:warm only fills the nearest one');
    }

    private function passes(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=green>{$detail}</>");
    }

    private function warns(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=yellow>{$detail}</>");
    }

    private function fails(string $label, string $detail): void
    {
        $this->components->twoColumnDetail($label, "<fg=red>{$detail}</>");
        $this->failed = true;
    }
}
