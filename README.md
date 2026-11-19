# Statamic Bunny Cache

Full static caching for [Statamic](https://statamic.com) on the [bunny.net](https://bunny.net) CDN.

Instead of writing pages to `public/static` and serving them with nginx rewrite rules, the `bunny` strategy lets the bunny.net pull zone in front of your site be the static cache:

- Every page Statamic would cache goes out with `Cache-Control: public, max-age=0, s-maxage=…` and a `CDN-Tag` header, and without cookies. bunny.net keeps it at the edge; browsers always revalidate.
- When Statamic invalidates pages (an editor saves an entry, a global, a nav, an asset…), the addon purges exactly what Statamic invalidates through the bunny.net API: single URLs, or all pages by their cache tag when the whole cache is cleared. Images, CSS and JS in the same pull zone stay cached.
- `{{ nocache }}` regions and CSRF tokens work exactly like Statamic's full measure caching: the cached page contains placeholders, and a small script fetches the per-visitor parts from your server.
- No nginx config, no `public/static` folder, no files on the server.

All purges of one request, command or queued job are collected and sent once, after the response has gone out, so saving in the CP stays fast. This also works in apps upgraded from Laravel 10 that still have an `app/Http/Kernel.php` without Laravel's `InvokeDeferredCallbacks` middleware. A failing purge is logged and never breaks a save.

## Requirements

- PHP 8.3+, Laravel 12 or 13, Statamic 6.0 or later
- A bunny.net pull zone in front of the whole site (the origin is your Laravel app)

## Installation

```bash
composer require kraenkvisuell/statamic-bunny-cache
```

Add to `.env`:

```dotenv
STATAMIC_STATIC_CACHING_STRATEGY=bunny
BUNNY_API_KEY=your-account-api-key
BUNNY_PULL_ZONE_ID=1234567
```

`BUNNY_API_KEY` is the **account** API key (bunny.net dashboard → Account settings → API), not a storage zone password. Several pull zones (e.g. one per domain) can be given as `BUNNY_PULL_ZONE_ID=1234567,1234568`.

That's it: the `bunny` strategy registers itself, there is nothing to add to `config/statamic/static_caching.php`. Without the API key or zone ID, pages are still sent as cacheable, but nothing is purged (a warning is logged), so leave the strategy off locally.

Optional settings (publish the config with `php artisan vendor:publish --tag=bunny-cache-config`):

| Env | Default | |
| --- | --- | --- |
| `BUNNY_CACHE_EDGE_TTL` | `2592000` (30 days) | How long bunny.net keeps a page. Purges make long values safe. |
| `BUNNY_CACHE_TAG` | `statamic-page` | The `CDN-Tag` of all pages, used to purge only pages. |
| `BUNNY_CACHE_TIMEOUT` | `10` | Timeout of bunny.net API calls in seconds. |

## Pull zone settings

Check them with:

```bash
php please bunny:check        # report
php please bunny:check --fix  # also turns cookie stripping off
```

`--fix` only touches cookie stripping. Set everything else in the bunny.net dashboard under **CDN → your pull zone → Caching**:

### Caching → General

| Setting | Value | Why |
| --- | --- | --- |
| **Smart Cache** | off | Smart Cache never caches `text/html`, so it would never cache a page. With it off, bunny.net follows the `Cache-Control` header the addon sets. |
| **Cache expiration time** | Respect origin Cache-Control | The addon decides what is cached and for how long. With a fixed time, the edge also caches the CP, logins and other private responses. |
| **Browser cache expiration time** | Match server cache expiration (default) | bunny.net then passes the addon's header to browsers unchanged (`max-age=0`), so a purge reaches everyone at once, while assets keep their own browser cache time. The other options are fixed times for every file, pages included. |
| **Query string sort** | on (default) | Harmless; one cached copy per set of parameters. |
| **Cache error response** | off | Errors should never be cached, not even for 5 seconds. |
| **Vary Cache** | only **URL Query String**, and only if your site's `config/statamic/static_caching.php` has `'ignore_query_strings' => false` (Statamic's default) | Statamic caches `/page?a=1` and `/page` as separate pages unless `ignore_query_strings` is `true`; bunny.net must do the same, or URL purges miss query string variants. With `true`, select nothing. Never select **Cookie Value**: that makes every visitor's copy separate. |
| **Vary Cache by Request Headers** | empty | |
| **Strip response cookies** | off | Otherwise bunny.net strips the session cookie from every response and nobody can log in to the CP. The addon already removes cookies from cached pages. |
| **Optimize for large object delivery** | off | Only for video and large downloads. |
| **Stale Cache** | **While Origin Offline** recommended | Keeps serving cached pages while your server is down. |

### Caching → Perma-Cache

Off. bunny.net can't purge by tag with Perma-Cache.

### Caching → Origin shield

On (recommended). Edge locations fetch from the shield instead of each asking your server to render the page, and `static:warm` fills the shield for all of them.

### Caching → Request coalescing

Off. It merges simultaneous requests for an uncached URL into one, so two people loading the same CP page or other private response at the same moment could get each other's.

## Deploying

Nothing to do in nginx: the default Laravel site config is all you need:

```nginx
location / {
    try_files $uri $uri/ /index.php?$query_string;
}
```

In the deploy script, after building the frontend:

```bash
php artisan config:cache
php please static:clear   # purges all pages at bunny.net (cached HTML points to the old CSS/JS)
php please static:warm    # optional: fills the cache again through bunny.net
```

### Switching from Statamic's file cache

If the site used full measure caching (`full` strategy) before, three things:

1. **Delete `public/static` on the server once** (`rm -rf public/static`). The `bunny` strategy never writes or deletes these files, and `static:clear` now purges at bunny.net instead of deleting them. As long as the old nginx rules are in place, nginx keeps serving these stale pages from disk without asking PHP, so edits never show up and purges don't help.
2. **Replace the `@static` rewrite rules** (Forge's "Statamic" site template adds them: `set $try_location @static;`, the two `if` blocks, `location @static` and `location @not_static`) with the default `location /` block above. Without files in `public/static` they only fall through to PHP, but removing them makes sure no stale file is ever served again.
3. **Purge the whole pull zone once** after deploying: `php please bunny:purge --zone`. bunny.net may still hold pages it cached before the switch (served by nginx from `public/static`, with bunny.net's default cache time). They have no `CDN-Tag`, so the tag purge after a save never removes them, and the home page in particular can stay stale for weeks. Browsers that got such a copy may keep it too (bunny.net sends them the same long `max-age`), so check with a hard reload.

## Commands

```bash
php please bunny:purge                          # purge all pages (by tag)
php please bunny:purge https://example.com/about https://example.com/blog/*
php please bunny:purge --zone                   # purge the whole pull zone, assets included
php please bunny:check [--fix]
```

`php please static:clear` purges all pages too.

## How it works

The `bunny` cacher extends Statamic's `FileCacher`, because Statamic adds the nocache and CSRF scripts only for file cachers, and then sends even the first visitor the same placeholder page as everybody else. That makes every response the cacher touches safe to share. The cacher never writes or reads a file:

- **Caching a page** records the URL in Statamic's URL list (so invalidation rules work as usual) and marks the response. Right before it is sent, the response gets the edge cache headers, loses its cookies, and its content becomes the version Statamic would have written to the cache file, with nocache and CSRF placeholders. (Since Statamic 6.3.2 Statamic sends that version to the first visitor anyway; before, the first visitor's own nocache regions and CSRF token were in it, and the addon takes care of that.)
- **Serving a page** never happens on the server: PHP renders every miss, bunny.net serves every hit.
- **Invalidating** a URL purges it (plus its query string variants Statamic knows about); wildcards become bunny.net prefix purges; flushing the cache purges the cache tag. "Refreshing" (background recache) purges as well, and the next visitor fills the edge.

## Things to know

- **Anything per visitor** (forms with old input, a logged-in user's name, a cookie banner's state) must be inside `{{ nocache }}` or `@nocache`, exactly like with Statamic's full measure caching.
- **`php artisan cache:clear`** also deletes the nocache regions Statamic keeps in the cache. Run `php please static:clear` after it, so bunny.net doesn't serve pages whose regions are gone.
- **Leave `STATAMIC_BACKGROUND_RECACHE` off.** It works, but brings nothing: there is no cached file to keep serving while a page re-renders, so "refreshing" a page just purges it like an invalidation does. With `rules: all` it is worse: Statamic then refreshes the URLs in its own URL list one by one instead of flushing, so the addon purges URL by URL instead of by tag, and misses any page bunny.net holds that Statamic's list doesn't know about (e.g. after `cache:clear`).
- **Purge rate limits:** bunny.net allows about 300 URL and tag purges and 30 prefix or zone purges per minute per account. The addon retries when it gets a `429`.
- **Editors** see the cached pages like everybody else; Live Preview and draft, private or protected pages are never cached.

## Support

Questions and bugs: [GitHub issues](https://github.com/kraenkvisuell/statamic-bunny-cache/issues). Please include your Statamic and Laravel versions and the output of `php please bunny:check`.

## Changelog

See the [GitHub releases](https://github.com/kraenkvisuell/statamic-bunny-cache/releases).

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
