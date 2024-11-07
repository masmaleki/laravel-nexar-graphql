<?php

namespace NexarGraphQL\Providers;

use Illuminate\Support\ServiceProvider;
use NexarGraphQL\App\Models\NexarToken;

class NexarGraphQLServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/nexar.php', 'nexar');

        $this->app->singleton('NexarGraphQL', function ($app) {
            return new \NexarGraphQL\Services\NexarGraphQLService();
        });

        // Register the model (optional, but helps with discovery in the package context)
        $this->app->bind('NexarToken', NexarToken::class);
            // Load migrations directly from the package
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../Config/nexar.php' => config_path('nexar.php'),
            ]);
        }

            // Publish migrations
            $this->publishes([
                __DIR__ . '/../database/migrations/' => database_path('migrations')
            ], 'migrations');
    }
}
