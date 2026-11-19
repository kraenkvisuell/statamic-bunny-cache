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

    private ?string $content = null;

    public function __construct(
        private readonly int $ttl,
        private readonly string $tag,
    ) {}

    /**
     * @param  string|null  $content  The page as Statamic would cache it: with
     *                                nocache and CSRF placeholders instead of
     *                                the current visitor's content.
     */
    public function mark(?string $content = null): void
    {
        $this->cacheable = true;
        $this->content = $content;
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
        $content = $this->content;
        $this->content = null;

        $request = $event->request;
        $response = $event->response;

        if (! in_array($request->method(), ['GET', 'HEAD'], true) || $response->getStatusCode() !== 200) {
            return;
        }

        // Send the version Statamic would have cached, not the one it rendered
        // for this visitor. Since Statamic 6.3.2 both are the same, but before
        // that the first visitor's response still had their own nocache
        // regions and CSRF token in it, which the edge would share with all.
        if ($content !== null && $content !== $response->getContent()) {
            $response->setContent($content);

            if ($response->headers->has('ETag')) {
                $response->setEtag(md5($content));
            }
        }

        // A cookie on a shared response would hand one visitor's session
        // (or CSRF token) to everybody else who gets the cached copy.
        $response->headers->remove('Set-Cookie');

        $response->headers->set('Cache-Control', "public, max-age=0, s-maxage={$this->ttl}");
        $response->headers->set('CDN-Tag', $this->tag);
    }
}
