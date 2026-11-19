<?php

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kraenkvisuell\StatamicBunnyCache\BunnyApi;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\StaticCache;

use function Illuminate\Support\defer;

beforeEach(function () {
    Http::preventStrayRequests();
});

function fakeBunny($response = null): void
{
    Http::fake(['api.bunny.net/*' => $response ?? Http::response(null, 204)]);
}

function purgedUrls(): array
{
    return Http::recorded()
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_starts_with($request->url(), 'https://api.bunny.net/purge?'))
        ->map(function (Request $request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY), $query);

            return $query['url'];
        })
        ->values()
        ->all();
}

function tagPurges(): array
{
    return Http::recorded()
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $request) => str_contains($request->url(), '/purgeCache'))
        ->map(fn (Request $request) => [$request->url(), $request->data()])
        ->values()
        ->all();
}

it('purges a page at bunny.net when its URL is invalidated', function () {
    fakeBunny();
    $this->get('/plain')->assertOk();

    StaticCache::driver()->invalidateUrls(['/plain']);
    defer()->invoke();

    expect(purgedUrls())->toBe(['http://localhost/plain'])
        ->and(StaticCache::driver()->getUrls()->all())->toBe([]);

    Http::assertSent(fn (Request $request) => $request->hasHeader('AccessKey', 'test-key') && $request->method() === 'POST');
});

it('purges a URL even if Statamic does not know it was cached', function () {
    fakeBunny();
    StaticCache::driver()->invalidateUrls(['/unknown']);
    defer()->invoke();

    expect(purgedUrls())->toBe(['http://localhost/unknown']);
});

it('purges a wildcard as a bunny.net prefix purge', function () {
    fakeBunny();
    $this->get('/plain')->assertOk();

    StaticCache::driver()->invalidateUrls(['/pl*']);
    defer()->invoke();

    expect(purgedUrls())->toBe(['http://localhost/plain', 'http://localhost/pl*']);
});

it('purges only the pages by tag when the whole static cache is cleared', function () {
    fakeBunny();
    $this->get('/plain')->assertOk();

    StaticCache::flush();
    defer()->invoke();

    expect(tagPurges())->toBe([
        ['https://api.bunny.net/pullzone/111/purgeCache', ['CacheTag' => 'statamic-page']],
    ])->and(purgedUrls())->toBe([])
        ->and(StaticCache::driver()->getUrls()->all())->toBe([]);
});

it('purges every configured pull zone', function () {
    fakeBunny();
    config(['bunny-cache.pull_zone_ids' => ['111', '222']]);
    app()->forgetInstance(BunnyApi::class);
    app()->forgetScopedInstances();

    StaticCache::flush();
    defer()->invoke();

    expect(array_column(tagPurges(), 0))->toBe([
        'https://api.bunny.net/pullzone/111/purgeCache',
        'https://api.bunny.net/pullzone/222/purgeCache',
    ]);
});

it('sends one purge after the response, however often Statamic invalidates', function () {
    fakeBunny();
    StaticCache::driver()->invalidateUrls(['/a', '/a', '/b']);
    StaticCache::flush();
    StaticCache::flush();

    Http::assertNothingSent();

    defer()->invoke();

    expect(tagPurges())->toHaveCount(1)
        ->and(purgedUrls())->toBe([]);
});

it('purges the pages when an editor saves an entry', function () {
    fakeBunny();
    Collection::make('pages')->routes('/{slug}')->save();
    defer()->invoke();
    $before = count(tagPurges());

    Entry::make()->collection('pages')->slug('hello')->data(['title' => 'Hello'])->save();
    defer()->invoke();

    expect(count(tagPurges()) - $before)->toBe(1);
});

it('purges after the response of a real request', function () {
    fakeBunny();
    Collection::make('pages')->routes('/{slug}')->save();
    defer()->invoke();
    $before = count(tagPurges());

    $this->post('/form')->assertOk();
    expect(count(tagPurges()))->toBe($before);

    $this->withoutMiddleware(ValidateCsrfToken::class)
        ->post('/save')
        ->assertOk()
        ->assertSee('saved');

    expect(count(tagPurges()) - $before)->toBe(1);
});

it('does nothing at bunny.net without credentials, but logs it', function () {
    fakeBunny();
    config(['bunny-cache.api_key' => null]);
    app()->forgetInstance(BunnyApi::class);
    app()->forgetScopedInstances();
    Log::spy();

    StaticCache::flush();
    defer()->invoke();

    Http::assertNothingSent();
    Log::shouldHaveReceived('warning')->once();
});

it('logs a failed purge instead of breaking the save', function () {
    fakeBunny(Http::response(['Message' => 'Unauthorized'], 401));
    Log::spy();

    StaticCache::flush();
    defer()->invoke();

    Log::shouldHaveReceived('error')->once();
});

it('retries when bunny.net rate limits the purge', function () {
    fakeBunny(Http::sequence()
        ->push(null, 429, ['Retry-After' => '1'])
        ->push(null, 204));
    Log::spy();

    StaticCache::flush();
    defer()->invoke();

    Http::assertSentCount(2);
    Log::shouldNotHaveReceived('error');
});
