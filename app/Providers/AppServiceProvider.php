<?php

namespace App\Providers;

use App\Services\Catalog\CatalogSearchCoordinator;
use App\Services\Catalog\CatalogSourceConfigParser;
use App\Services\Catalog\CatalogSourceRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CatalogSourceConfigParser::class, function () {
            return new CatalogSourceConfigParser;
        });

        $this->app->singleton(CatalogSourceRegistry::class, function (Application $app) {
            return new CatalogSourceRegistry(
                configParser: $app->make(CatalogSourceConfigParser::class),
                rawSourcesJson: config('catalog.sources'),
                mode: (string) config('catalog.mode', 'auto'),
                defaultTimeoutSeconds: (float) config('catalog.per_source_timeout', 2.0),
                logger: $app->make('log')
            );
        });

        $this->app->singleton(CatalogSearchCoordinator::class, function (Application $app) {
            $registry = $app->make(CatalogSourceRegistry::class);

            return new CatalogSearchCoordinator(
                adapters: $registry->getAdapters(),
                perSourceTimeoutSeconds: (float) config('catalog.per_source_timeout', 2.0),
                globalTimeoutSeconds: (float) config('catalog.global_timeout', 5.0),
                logger: $app->make('log')
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
