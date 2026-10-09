<?php

namespace Chargefield\Fulfillment;

use Chargefield\Fulfillment\Contracts\PrintProvider;
use Chargefield\Fulfillment\FulfillmentManager;
use Illuminate\Support\ServiceProvider;

class FulfillmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FulfillmentManager::class, fn ($app) => new FulfillmentManager($app));

        // Type-hint PrintProvider anywhere to get the configured default provider.
        $this->app->bind(PrintProvider::class, fn ($app) => $app->make(FulfillmentManager::class)->driver());
    }
}
