<?php

namespace Exdeliver\Drift;

use Exdeliver\Drift\Commands\AnalyzeCodeDriftsCommand;
use Exdeliver\Drift\Commands\GenerateBaseLineCommand;
use Illuminate\Support\ServiceProvider;

class DriftServiceProvider extends ServiceProvider
{
    /**
     * Perform post-registration booting of services.
     */
    public function boot(): void
    {
        // Publishing is only necessary when using the CLI.
        if ($this->app->runningInConsole()) {
            $this->bootForConsole();
        }
    }

    /**
     * Register any package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/drift.php', 'drift');
    }

    /**
     * Get the services provided by the provider.
     */
    public function provides(): array
    {
        return ['drift'];
    }

    /**
     * Console-specific booting.
     */
    protected function bootForConsole(): void
    {
        // Publishing the configuration file.
        $this->publishes([
            __DIR__ . '/../config/drift.php' => config_path('drift.php'),
        ], 'drift.config',);

        // Registering package commands.
        $this->commands([
            GenerateBaseLineCommand::class,
            AnalyzeCodeDriftsCommand::class,
        ]);
    }
}
