<?php

namespace Kraenkvisuell\StatamicBunnyCache;

use Illuminate\Support\Facades\Log;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Collects everything Statamic invalidates during one request, command or
 * queued job, and purges it at bunny.net once, after the response is sent.
 */
class PendingPurge
{
    /** @var array<string, true> */
    private array $urls = [];

    private bool $everything = false;

    private bool $scheduled = false;

    public function __construct(
        private readonly BunnyApi $api,
        private readonly string $tag,
    ) {}

    public function url(string $url): void
    {
        $this->urls[$url] = true;

        $this->schedule();
    }

    public function everything(): void
    {
        $this->everything = true;

        $this->schedule();
    }

    /**
     * @return array<int, string>
     */
    public function urls(): array
    {
        return array_keys($this->urls);
    }

    public function isEverything(): bool
    {
        return $this->everything;
    }

    public function run(): void
    {
        $urls = $this->urls();
        $everything = $this->everything;

        $this->urls = [];
        $this->everything = false;
        $this->scheduled = false;

        if (! $everything && $urls === []) {
            return;
        }

        if (! $this->api->isConfigured()) {
            Log::warning('Bunny cache: BUNNY_API_KEY or BUNNY_PULL_ZONE_ID is not set, so nothing was purged at bunny.net.');

            return;
        }

        try {
            $everything
                ? $this->api->purgeTag($this->tag)
                : $this->api->purgeUrls($urls);
        } catch (Throwable $e) {
            Log::error('Bunny cache: purging bunny.net failed: '.$e->getMessage(), [
                'urls' => $everything ? ['tag:'.$this->tag] : $urls,
            ]);
        }
    }

    private function schedule(): void
    {
        if ($this->scheduled) {
            return;
        }

        $this->scheduled = true;

        defer(fn () => $this->run(), 'bunny-cache-purge', always: true);
    }
}
