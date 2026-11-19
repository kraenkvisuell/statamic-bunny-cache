<?php

namespace Kraenkvisuell\StatamicBunnyCache;

use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Support\Facades\Event;
use Kraenkvisuell\StatamicBunnyCache\Console\CheckCommand;
use Kraenkvisuell\StatamicBunnyCache\Console\PurgeCommand;
use Statamic\Providers\AddonServiceProvider;
use Statamic\StaticCaching\StaticCacheManager;

class ServiceProvider extends AddonServiceProvider
{
    protected $commands = [
        CheckCommand::class,
        PurgeCommand::class,
    ];

    public function register(): void
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/bunny-cache.php', 'bunny-cache');

        // `STATAMIC_STATIC_CACHING_STRATEGY=bunny` works without touching
        // config/statamic/static_caching.php.
        if (! config()->has('statamic.static_caching.strategies.bunny')) {
            config()->set('statamic.static_caching.strategies.bunny', ['driver' => 'bunny']);
        }

        $this->app->singleton(BunnyApi::class, fn () => new BunnyApi(
            config('bunny-cache.api_key'),
            (array) config('bunny-cache.pull_zone_ids', []),
            (int) config('bunny-cache.timeout', 10),
        ));

        $this->app->scoped(PendingPurge::class, fn ($app) => new PendingPurge(
            $app->make(BunnyApi::class),
            (string) config('bunny-cache.tag'),
        ));

        $this->app->scoped(EdgeCacheableResponse::class, fn () => new EdgeCacheableResponse(
            (int) config('bunny-cache.edge_ttl'),
            (string) config('bunny-cache.tag'),
        ));

        $this->app->extend(StaticCacheManager::class, function (StaticCacheManager $manager) {
            return $manager->extend('bunny', fn ($app, array $config) => new BunnyCacher($manager->cacheStore(), $config));
        });
    }

    public function bootAddon(): void
    {
        $this->publishes([
            __DIR__.'/../config/bunny-cache.php' => config_path('bunny-cache.php'),
        ], 'bunny-cache-config');

        Event::listen(RequestHandled::class, fn (RequestHandled $event) => $this->app->make(EdgeCacheableResponse::class)->handle($event));
    }
}
