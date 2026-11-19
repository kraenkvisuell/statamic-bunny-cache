<?php

namespace Kraenkvisuell\StatamicBunnyCache;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class BunnyApi
{
    public const BASE_URL = 'https://api.bunny.net';

    /**
     * @param  array<int, string>  $pullZoneIds
     */
    public function __construct(
        private readonly ?string $apiKey,
        private readonly array $pullZoneIds,
        private readonly int $timeout = 10,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && $this->pullZoneIds !== [];
    }

    /**
     * @return array<int, string>
     */
    public function pullZoneIds(): array
    {
        return $this->pullZoneIds;
    }

    /**
     * Purge single URLs. A URL ending in * purges everything below it.
     *
     * @param  array<int, string>  $urls
     *
     * @throws RequestException
     */
    public function purgeUrls(array $urls): void
    {
        foreach ($urls as $url) {
            $this->request()->post('/purge?'.http_build_query(['url' => $url]))->throw();
        }
    }

    /**
     * Purge every file with the given CDN-Tag in all configured pull zones.
     *
     * @throws RequestException
     */
    public function purgeTag(string $tag): void
    {
        foreach ($this->pullZoneIds as $id) {
            $this->request()->post("/pullzone/{$id}/purgeCache", ['CacheTag' => $tag])->throw();
        }
    }

    /**
     * Purge every file in all configured pull zones, including assets.
     *
     * @throws RequestException
     */
    public function purgeZones(): void
    {
        foreach ($this->pullZoneIds as $id) {
            $this->request()->post("/pullzone/{$id}/purgeCache")->throw();
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException
     */
    public function pullZone(string $id): array
    {
        return (array) $this->request()->get("/pullzone/{$id}")->throw()->json();
    }

    /**
     * @param  array<string, mixed>  $settings
     *
     * @throws RequestException
     */
    public function updatePullZone(string $id, array $settings): void
    {
        $this->request()->post("/pullzone/{$id}", $settings)->throw();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['AccessKey' => (string) $this->apiKey])
            ->acceptJson()
            ->timeout($this->timeout)
            ->retry(
                3,
                fn (int $attempt, Throwable $e) => $this->retryDelay($attempt, $e),
                fn (Throwable $e) => $e instanceof RequestException && $e->response->status() === 429,
                throw: false,
            );
    }

    private function retryDelay(int $attempt, Throwable $e): int
    {
        $retryAfter = $e instanceof RequestException ? (int) $e->response->header('Retry-After') : 0;

        return $retryAfter > 0 ? min($retryAfter, 10) * 1000 : $attempt * 1000;
    }
}
