<?php

namespace ThinkNeverland\Porter;

use Illuminate\Support\ServiceProvider;
use ThinkNeverland\Porter\Commands\ExportCommand;
use ThinkNeverland\Porter\Commands\ImportCommand;
use ThinkNeverland\Porter\Commands\CloneS3Command;

class PorterServiceProvider extends ServiceProvider
{
    /**
     * Register the package's configuration and services.
     */
    public function register()
    {
        // Merge the package config with the app's config.
        $this->mergeConfigFrom(__DIR__ . '/../config/porter.php', 'porter');
    }

    /**
     * Boot the package (publish files, register commands).
     */
    public function boot()
    {
        // Register Artisan commands if the application is running in the console.
        if ($this->app->runningInConsole()) {
            $this->commands([
                ExportCommand::class,
                ImportCommand::class,
                CloneS3Command::class,
            ]);
        }

        // Publish the package configuration file to the main config directory.
        $this->publishes([
            __DIR__ . '/../config/porter.php' => config_path('porter.php'),
        ], 'porter-config');
    }
}
