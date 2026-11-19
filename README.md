# Statamic Bunny Cache

Full static caching for [Statamic](https://statamic.com) on the [bunny.net](https://bunny.net) CDN.

Instead of writing pages to `public/static` and serving them with nginx rewrite rules, the `bunny` strategy lets the bunny.net pull zone in front of your site be the static cache:

- Every page Statamic would cache goes out with `Cache-Control: public, max-age=0, s-maxage=…` and a `CDN-Tag` header, and without cookies. bunny.net keeps it at the edge; browsers always revalidate.
- When Statamic invalidates pages (an editor saves an entry, a global, a nav, an asset…), the addon purges exactly what Statamic invalidates through the bunny.net API: single URLs, or all pages by their cache tag when the whole cache is cleared. Images, CSS and JS in the same pull zone stay cached.
- `{{ nocache }}` regions and CSRF tokens work exactly like Statamic's full measure caching: the cached page contains placeholders, and a small script fetches the per-visitor parts from your server.
- No nginx config, no `public/static` folder, no files on the server.

All purges of one request, command or queued job are collected and sent once, after the response has gone out, so saving in the CP stays fast. A failing purge is logged and never breaks a save.

## Requirements

- PHP 8.3+, Laravel 12 or 13, Statamic 6
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

`BUNNY_API_KEY` is the **account** API key (bunny.net dashboard → Account settings → API), not a storage zone password. Several pull zones (e.g. one per domain) can be given as `BUNNY_PULL_ZONE_ID=123,456`.

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

| Setting | Must be | Why |
| --- | --- | --- |
| Caching → General → **Disable cookies** | off | Otherwise bunny.net strips the session cookie from every response and nobody can log in to the CP. Cached pages never carry cookies anyway. |
| Caching → General → **Cache expiration time** | Respect origin Cache-Control | The addon decides what is cached. With a fixed override the edge also caches the CP and other private responses. |
| **Perma-Cache** | off | bunny.net can't purge by tag with Perma-Cache. |
| **Vary by query string** | match Statamic's `ignore_query_strings` | Otherwise URL purges miss query string variants. |
| **Origin Shield** | on (recommended) | Edge locations fetch from the shield instead of each asking your server to render the page, and `static:warm` fills the shield for all of them. |

## Deploying

Nothing to do in nginx: the default Laravel site config is all you need. If the site used Statamic's file cache before, remove the `@static` rewrite rules and delete `public/static`.

In the deploy script, after building the frontend:

```bash
php artisan config:cache
php please static:clear   # purges all pages at bunny.net (cached HTML points to the old CSS/JS)
php please static:warm    # optional: fills the cache again through bunny.net
```

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

- **Caching a page** records the URL in Statamic's URL list (so invalidation rules work as usual) and marks the response. Right before it is sent, the response gets the edge cache headers and loses its cookies.
- **Serving a page** never happens on the server: PHP renders every miss, bunny.net serves every hit.
- **Invalidating** a URL purges it (plus its query string variants Statamic knows about); wildcards become bunny.net prefix purges; flushing the cache purges the cache tag. "Refreshing" (background recache) purges as well, and the next visitor fills the edge.

## Things to know

- **Anything per visitor** (forms with old input, a logged-in user's name, a cookie banner's state) must be inside `{{ nocache }}` or `@nocache`, exactly like with Statamic's full measure caching.
- **`php artisan cache:clear`** also deletes the nocache regions Statamic keeps in the cache. Run `php please static:clear` after it, so bunny.net doesn't serve pages whose regions are gone.
- **Purge rate limits:** bunny.net allows about 300 URL and tag purges and 30 prefix or zone purges per minute per account. The addon retries when it gets a `429`.
- **Editors** see the cached pages like everybody else; Live Preview and draft, private or protected pages are never cached.

## Testing

```bash
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
