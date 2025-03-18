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
        // Publish config with the provider name as tag
        $this->publishes([
            __DIR__ . '/../config/updater.php' => config_path('updater.php')
        ], 'Shah\LaravelUpdater\UpdaterServiceProvider');

        // keep the named tag as well
        $this->publishes([
            __DIR__ . '/../config/updater.php' => config_path('updater.php')
        ], 'updater');

        // $this->publishes([
        //     __DIR__ . '/../resources/views' => resource_path('views/vendor/updater'),
        // ], 'updater');

        // $this->publishes([
        //     __DIR__ . '/../resources/lang' => resource_path('lang/vendor/updater'),
        // ], 'updater');

        // Load routes, views, and translations
        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        // $this->loadViewsFrom(__DIR__ . '/../resources/views', 'updater');
        // $this->loadTranslationsFrom(__DIR__ . '/../resources/lang', 'updater');
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
            return $app->make(Updater::class);
        });
    }
}
