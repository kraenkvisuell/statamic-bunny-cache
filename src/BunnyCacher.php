<?php

namespace Kraenkvisuell\StatamicBunnyCache;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Http\Request;
use LogicException;
use Statamic\Events\UrlInvalidated;
use Statamic\StaticCaching\Cachers\FileCacher;
use Statamic\StaticCaching\Cachers\Writer;
use Statamic\StaticCaching\Page;

/**
 * A full measure cacher whose cache is the bunny.net edge instead of files.
 *
 * It extends the FileCacher because Statamic only adds the nocache and CSRF
 * JavaScript (and serves the first visitor the same page as everybody else)
 * for file cachers. It never writes or reads a file: PHP renders every miss,
 * bunny.net serves every hit, and invalidating a URL purges it at bunny.net.
 */
class BunnyCacher extends FileCacher
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(Repository $cache, $config)
    {
        parent::__construct(new Writer, $cache, $config);
    }

    /**
     * @param  mixed  $content
     * @return void
     */
    public function cachePage(Request $request, $content)
    {
        $url = $this->getUrl($request);

        if ($this->isExcluded($url)) {
            return;
        }

        $this->cacheUrl($this->makeHash($url), ...$this->getPathAndDomain($url));

        app(EdgeCacheableResponse::class)->mark($this->normalizeContent($content));
    }

    public function hasCachedPage(Request $request)
    {
        return false;
    }

    /**
     * @return Page
     */
    public function getCachedPage(Request $request)
    {
        throw new LogicException('Pages cached by the bunny cacher live on bunny.net, not on this server.');
    }

    public function flush()
    {
        $this->flushUrls();

        app(PendingPurge::class)->everything();
    }

    /**
     * @param  string  $url
     * @param  string|null  $domain
     */
    public function invalidateUrl($url, $domain = null)
    {
        $domain = $domain ?: $this->getBaseUrl();

        $this->getUrls($domain)
            ->filter(fn ($value) => $value === $url || str_starts_with($value, $url.'?'))
            ->each(function ($value, $key) use ($domain) {
                $this->forgetUrl($key, $domain);
                app(PendingPurge::class)->url($domain.$value);
            });

        // The edge may hold the page even if Statamic's URL list doesn't
        // (e.g. after `cache:clear`), so always purge the URL itself.
        app(PendingPurge::class)->url($domain.$url);

        UrlInvalidated::dispatch($url, $domain);
    }

    /**
     * @param  string  $wildcard
     * @return void
     */
    protected function invalidateWildcardUrl($wildcard)
    {
        parent::invalidateWildcardUrl($wildcard);

        [$prefix, $domain] = $this->getPathAndDomain(substr($wildcard, 0, -1));

        app(PendingPurge::class)->url($domain.$prefix.'*');
    }

    /**
     * There is nothing to re-render on this server: purge the page and let
     * the next visitor's request fill the edge again.
     *
     * @param  string  $url
     * @param  string|null  $domain
     */
    public function refreshUrl($url, $domain = null)
    {
        $this->invalidateUrl($url, $domain);
    }

    /**
     * @param  string  $wildcard
     * @return void
     */
    protected function refreshWildcardUrl($wildcard)
    {
        $this->invalidateWildcardUrl($wildcard);
    }
}
