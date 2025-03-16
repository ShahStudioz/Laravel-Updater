<?php

namespace Shah\LaravelUpdater;

use Illuminate\Support\ServiceProvider;

class UpdaterServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        // Publish config, views, and translations
        $this->publishes([
            __DIR__ . '/../config/updater.php' => config_path('updater.php'),
        ], 'updater-config');

        $this->publishes([
            __DIR__ . '/../resources/views' => resource_path('views/vendor/updater'),
        ], 'updater-views');

        $this->publishes([
            __DIR__ . '/../resources/lang' => resource_path('lang/vendor/updater'),
        ], 'updater-translations');

        // Load routes, views, and translations
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'updater');
        $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'updater');
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
        // Merge config
        $this->mergeConfigFrom(__DIR__ . '/../config/updater.php', 'updater');

        // Register the facade
        $this->app->singleton('updater', function ($app) {
            return new Updater();
        });
    }
}
