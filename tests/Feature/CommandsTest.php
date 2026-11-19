<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Kraenkvisuell\StatamicBunnyCache\BunnyApi;

beforeEach(function () {
    Http::preventStrayRequests();
});

function healthyZone(array $overrides = []): array
{
    return array_merge([
        'Id' => 111,
        'Name' => 'example',
        'DisableCookies' => false,
        'CacheControlMaxAgeOverride' => -1,
        'PermaCacheStorageZoneId' => 0,
        'IgnoreQueryStrings' => false,
        'EnableOriginShield' => true,
        'EnableSmartCache' => false,
        'EnableRequestCoalescing' => false,
    ], $overrides);
}

it('purges all pages by tag', function () {
    Http::fake(['api.bunny.net/*' => Http::response(null, 204)]);

    $this->artisan('statamic:bunny:purge')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bunny.net/pullzone/111/purgeCache'
        && $request->data() === ['CacheTag' => 'statamic-page']);
});

it('purges the whole zone', function () {
    Http::fake(['api.bunny.net/*' => Http::response(null, 204)]);

    $this->artisan('statamic:bunny:purge --zone')->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bunny.net/pullzone/111/purgeCache'
        && $request->data() === []);
});

it('purges single URLs', function () {
    Http::fake(['api.bunny.net/*' => Http::response(null, 204)]);

    $this->artisan('statamic:bunny:purge', ['url' => ['https://example.com/a', 'https://example.com/b*']])->assertSuccessful();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://api.bunny.net/purge?url='.urlencode('https://example.com/b*'));
});

it('fails the purge command without credentials', function () {
    config(['bunny-cache.api_key' => null]);
    app()->forgetInstance(BunnyApi::class);

    $this->artisan('statamic:bunny:purge')->assertFailed();
    Http::assertNothingSent();
});

it('fails the purge command when bunny.net refuses', function () {
    Http::fake(['api.bunny.net/*' => Http::response(['Message' => 'Unauthorized'], 401)]);

    $this->artisan('statamic:bunny:purge')->assertFailed();
});

it('passes the check for a correctly set up zone', function () {
    Http::fake(['api.bunny.net/pullzone/111' => Http::response(healthyZone())]);

    $this->artisan('statamic:bunny:check')
        ->expectsOutputToContain('respects the origin')
        ->assertSuccessful();
});

it('fails the check when the zone strips cookies, overrides the cache time or uses Perma-Cache', function (array $zone, string $message) {
    Http::fake(['api.bunny.net/pullzone/111' => Http::response(healthyZone($zone))]);

    $this->artisan('statamic:bunny:check')
        ->expectsOutputToContain($message)
        ->assertFailed();
})->with([
    'cookies' => [['DisableCookies' => true], 'logins and other sessions break'],
    'cache time' => [['CacheControlMaxAgeOverride' => 3600], 'overridden (3600 s)'],
    'perma-cache' => [['PermaCacheStorageZoneId' => 42], 'cannot purge by tag'],
    'smart cache' => [['EnableSmartCache' => true], 'it never caches HTML'],
    'request coalescing' => [['EnableRequestCoalescing' => true], 'get each other'],
]);

it('turns cookie stripping off with --fix', function () {
    Http::fake([
        'api.bunny.net/pullzone/111' => Http::sequence()
            ->push(healthyZone(['DisableCookies' => true]))
            ->push(null, 204),
    ]);

    $this->artisan('statamic:bunny:check --fix')
        ->expectsOutputToContain('turned off')
        ->assertSuccessful();

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://api.bunny.net/pullzone/111'
        && $request->data() === ['DisableCookies' => false]);
});

it('warns about mismatched query string handling and a missing origin shield', function () {
    config(['statamic.static_caching.ignore_query_strings' => true]);
    Http::fake(['api.bunny.net/pullzone/111' => Http::response(healthyZone(['EnableOriginShield' => false]))]);

    $this->artisan('statamic:bunny:check')
        ->expectsOutputToContain('Statamic ignores them')
        ->expectsOutputToContain('static:warm only fills the nearest one')
        ->assertSuccessful();
});

it('is available as a please command', function () {
    expect(collect(Artisan::all())->keys())
        ->toContain('statamic:bunny:check', 'statamic:bunny:purge');
});
