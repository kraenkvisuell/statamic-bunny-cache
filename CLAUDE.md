# CLAUDE.md

Statamic addon `kraenkvisuell/statamic-bunny-cache` (namespace `Kraenkvisuell\StatamicBunnyCache`): a static caching strategy `bunny` that makes the bunny.net pull zone in front of a Statamic 6 site the full measure cache, and purges it through the bunny.net API when Statamic invalidates pages. See README.md for what users see.

## Commands

- `composer test`: PHPStan (level 6, `src`), Pint check, Pest
- `vendor/bin/pest --filter="purges a wildcard"`: single test
- `composer lint`: fix formatting

## Architecture

- `ServiceProvider` registers the `bunny` driver on `StaticCacheManager` (and the strategy config if missing), the `BunnyApi` singleton, the scoped `PendingPurge` and `EdgeCacheableResponse`, the two `please` commands, and a `RequestHandled` listener.
- `BunnyCacher` extends `FileCacher` on purpose: Statamic's `NoCacheReplacer`, `CsrfTokenReplacer` and `ScriptController` only output the placeholder + JS page (also to the first visitor) for `instanceof FileCacher`. It never touches files: `hasCachedPage()` is always false, `cachePage()` records the URL and marks the response, invalidation queues purges. Re-check these `instanceof FileCacher` places in `vendor/statamic/cms/src/StaticCaching` when Statamic changes static caching.
- `EdgeCacheableResponse` (on `RequestHandled`, after all middleware) strips cookies and sets `Cache-Control: public, max-age=0, s-maxage` + `CDN-Tag` on marked 200 GET/HEAD responses only.
- `PendingPurge` collects URLs / "everything" and runs once via a named `defer()` (`always: true`): after the HTTP response, after an artisan/please command, after a queued job. "Everything" purges by `CDN-Tag`, not the whole zone, so assets stay cached.
- Tests: Statamic `AddonTestCase` + testbench, routes and views in `tests/TestCase.php` / `tests/__fixtures__/views`, `Http::fake` for api.bunny.net, `defer()->invoke()` to run pending purges. A second `Http::fake()` in a test does not override an earlier stub, so fake once per test (`fakeBunny()`).
