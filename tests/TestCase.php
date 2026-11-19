<?php

namespace Kraenkvisuell\StatamicBunnyCache\Tests;

use Illuminate\Support\Facades\Route;
use Kraenkvisuell\StatamicBunnyCache\ServiceProvider;
use Statamic\Facades\Entry;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('app.url', 'http://localhost');
        $app['config']->set('statamic.static_caching.strategy', 'bunny');
        $app['config']->set('statamic.static_caching.invalidation.rules', 'all');
        $app['config']->set('statamic.editions.pro', false);
        $app['config']->set('bunny-cache.api_key', 'test-key');
        $app['config']->set('bunny-cache.pull_zone_ids', ['111']);
        $app['config']->set('view.paths', [__DIR__.'/__fixtures__/views']);
    }

    protected function defineRoutes($router)
    {
        Route::middleware(['web', 'statamic.web'])->group(function () {
            Route::statamic('/about', 'about');
            Route::statamic('/plain', 'plain');
        });

        Route::middleware('web')->group(function () {
            Route::post('/form', fn () => response('posted'));
            Route::get('/not-statamic', fn () => response('hello'));
            Route::post('/save', function () {
                Entry::make()->collection('pages')->slug('saved')->data(['title' => 'Saved'])->save();

                return response('saved');
            });
        });
    }
}
