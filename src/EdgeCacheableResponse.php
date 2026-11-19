<?php

namespace Kraenkvisuell\StatamicBunnyCache;

use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Remembers whether Statamic cached the current page, and when it did, turns
 * the outgoing response into one bunny.net may cache and share.
 */
class EdgeCacheableResponse
{
    private bool $cacheable = false;

    public function __construct(
        private readonly int $ttl,
        private readonly string $tag,
    ) {}

    public function mark(): void
    {
        $this->cacheable = true;
    }

    public function isMarked(): bool
    {
        return $this->cacheable;
    }

    public function handle(RequestHandled $event): void
    {
        if (! $this->cacheable) {
            return;
        }

        // One mark covers one response, even where the container outlives
        // the request (Octane, tests).
        $this->cacheable = false;

        $request = $event->request;
        $response = $event->response;

        if (! in_array($request->method(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return;
        }

        // A cookie on a shared response would hand one visitor's session
        // (or CSRF token) to everybody else who gets the cached copy.
        $response->headers->remove('Set-Cookie');

        $response->headers->set('Cache-Control', "public, max-age=0, s-maxage={$this->ttl}");
        $response->headers->set('CDN-Tag', $this->tag);
    }
}
