<?php

namespace NexarGraphQL\Providers;

use Illuminate\Support\ServiceProvider;
use NexarGraphQL\App\Models\NexarToken;
use NexarGraphQL\Commands\ListAttributesCommand;
use NexarGraphQL\Services\NexarGraphQLService;

class NexarGraphQLServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/nexar.php', 'nexar');

        $this->app->bind('NexarGraphQL', function ($app) {
            return new NexarGraphQLService();
        });

        $this->app->bind('NexarToken', NexarToken::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../Config/nexar.php' => config_path('nexar.php'),
            ], 'nexar-config');

            $this->publishes([
                __DIR__ . '/../Database/Migrations/' => database_path('migrations'),
            ], 'nexar-migrations');

            $this->commands([
                ListAttributesCommand::class,
            ]);
        }
    }
}
