<?php

namespace Chargefield\Fulfillment;

use Chargefield\Fulfillment\Contracts\PrintProvider;
use Chargefield\Fulfillment\Providers\GelatoProvider;
use Chargefield\Fulfillment\Providers\ManualProvider;
use Chargefield\Fulfillment\Providers\PrintfulProvider;
use Chargefield\Fulfillment\Shipping\ShippingRates;
use Illuminate\Support\Manager;

/**
 * Resolves the active print-on-demand provider. `->driver()` returns the one
 * named by services.fulfillment.default; `->driver('printful')` forces a
 * specific provider. Add a provider by implementing PrintProvider and adding a
 * create<Name>Driver() method here.
 *
 * @method PrintProvider driver(string|null $driver = null)
 */
class FulfillmentManager extends Manager
{
    public function getDefaultDriver(): string
    {
        return $this->config->get('services.fulfillment.default', 'gelato');
    }

    public function createGelatoDriver(): PrintProvider
    {
        return new GelatoProvider(
            (string) $this->config->get('services.gelato.key'),
        );
    }

    public function createPrintfulDriver(): PrintProvider
    {
        return new PrintfulProvider(
            (string) $this->config->get('services.printful.key'),
            $this->config->get('services.printful.store_id'),
        );
    }

    public function createManualDriver(): PrintProvider
    {
        return new ManualProvider($this->container->make(ShippingRates::class));
    }
}
