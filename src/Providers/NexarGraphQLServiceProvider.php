<?php

namespace NexarGraphQL\Providers;

use Illuminate\Support\ServiceProvider;

class NexarGraphQLServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/nexar.php', 'nexar');

        $this->app->singleton('NexarGraphQL', function ($app) {
            return new \NexarGraphQL\Services\NexarGraphQLService();
        });
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../Config/nexar.php' => config_path('nexar.php'),
            ]);
        }
    }
}
