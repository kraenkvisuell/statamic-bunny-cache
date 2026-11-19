<?php

namespace Kraenkvisuell\StatamicBunnyCache\Console;

use Illuminate\Console\Command;
use Kraenkvisuell\StatamicBunnyCache\BunnyApi;
use Statamic\Console\RunsInPlease;
use Throwable;

class PurgeCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:bunny:purge
        {url?* : Purge only these URLs (absolute; a trailing * purges everything below)}
        {--zone : Purge the whole pull zone, including images, CSS and JS}';

    protected $description = 'Purge the cached pages at bunny.net';

    public function handle(BunnyApi $api): int
    {
        if (! $api->isConfigured()) {
            $this->components->error('Set BUNNY_API_KEY and BUNNY_PULL_ZONE_ID first.');

            return self::FAILURE;
        }

        /** @var array<int, string> $urls */
        $urls = $this->argument('url');
        $tag = (string) config('bunny-cache.tag');

        try {
            if ($urls !== []) {
                $api->purgeUrls($urls);
                $this->components->info('Purged '.count($urls).' URL(s) at bunny.net.');
            } elseif ($this->option('zone')) {
                $api->purgeZones();
                $this->components->info('Purged the whole pull zone at bunny.net.');
            } else {
                $api->purgeTag($tag);
                $this->components->info("Purged all pages (CDN-Tag \"{$tag}\") at bunny.net.");
            }
        } catch (Throwable $e) {
            $this->components->error('Purging failed: '.$e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
