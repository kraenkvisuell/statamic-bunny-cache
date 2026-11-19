<?php

use Illuminate\Support\Facades\Http;
use Kraenkvisuell\StatamicBunnyCache\BunnyCacher;
use Statamic\Facades\StaticCache;
use Statamic\Facades\Token;
use Statamic\StaticCaching\Cacher;
use Statamic\Tokens\Handlers\LivePreview;

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake(['api.bunny.net/*' => Http::response(null, 204)]);
});

it('registers the bunny strategy without touching the static caching config', function () {
    expect(config('statamic.static_caching.strategies.bunny'))->toBe(['driver' => 'bunny'])
        ->and(app(Cacher::class))->toBeInstanceOf(BunnyCacher::class);
});

it('lets bunny.net cache a page and keeps it free of cookies', function () {
    $response = $this->get('/plain');

    $response->assertOk()
        ->assertSee('Plain')
        ->assertHeader('CDN-Tag', 'statamic-page');

    expect($response->headers->get('Cache-Control'))->toContain('public')
        ->toContain('max-age=0')
        ->toContain('s-maxage=2592000')
        ->and($response->headers->getCookies())->toBe([])
        ->and($response->headers->has('Set-Cookie'))->toBeFalse();
});

it('records the page so Statamic can invalidate it later', function () {
    $this->get('/plain')->assertOk();

    expect(StaticCache::driver()->getUrls()->values()->all())->toBe(['/plain']);
});

it('never stores or serves a page itself', function () {
    $this->get('/plain')->assertOk();

    expect(collect(app('files')->allFiles(public_path()))->filter(fn ($file) => str_ends_with($file->getFilename(), '.html')))->toBeEmpty();

    $this->get('/plain')->assertOk()->assertHeader('CDN-Tag', 'statamic-page');
});

it('sends every visitor the nocache placeholder and script instead of per visitor content', function () {
    $response = $this->get('/about');

    $response->assertOk()
        ->assertDontSee('Per visitor')
        ->assertSee('data-nocache=', false)
        ->assertSee('/!/nocache', false)
        ->assertHeader('CDN-Tag', 'statamic-page');

    // The CSRF token in the page is a placeholder that the CSRF script fills in.
    expect($response->getContent())->toContain('STATAMIC_CSRF_TOKEN')
        ->not->toContain(csrf_token());
});

it('fills the nocache regions through the nocache endpoint', function () {
    $html = $this->get('/about')->getContent();

    preg_match('/data-nocache="([\w\d]+)"/', $html, $matches);

    $this->postJson('/!/nocache', ['url' => 'http://localhost/about', 'sections' => [$matches[1]]])
        ->assertOk()
        ->assertSee('Per visitor');
});

it('leaves responses alone that Statamic does not cache', function (string $method, string $uri) {
    $response = $this->call($method, $uri);

    expect($response->headers->has('CDN-Tag'))->toBeFalse()
        ->and($response->headers->get('Cache-Control'))->not->toContain('public');
})->with([
    'non-Statamic route' => ['GET', '/not-statamic'],
    'POST request' => ['POST', '/form'],
    'missing page' => ['GET', '/does-not-exist'],
]);

it('does not cache pages with a live preview token', function () {
    $token = Token::make(null, LivePreview::class);
    $token->save();

    $response = $this->get('/plain?token='.$token->token());

    $response->assertOk();

    expect($response->headers->has('CDN-Tag'))->toBeFalse();
});

it('does not touch pages when another strategy is active', function () {
    config(['statamic.static_caching.strategy' => null]);
    app()->forgetInstance(Cacher::class);

    $response = $this->get('/plain');

    $response->assertOk();
    expect($response->headers->has('CDN-Tag'))->toBeFalse();
});
