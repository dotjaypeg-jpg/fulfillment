<?php

namespace Chargefield\Fulfillment\Shipping;

use Chargefield\Fulfillment\Contracts\ShippingCarrier;
use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\ShippingRate;
use Illuminate\Contracts\Container\Container;

/**
 * Pools shipping rates across every enabled {@see ShippingCarrier} for
 * self-shipped items. Carriers are resolved from config('services.shipping'):
 * a `registry` map (key => class) and a `carriers` list of enabled keys. Add a
 * carrier (e.g. Purolator) by implementing ShippingCarrier, registering it, and
 * enabling its key — the quote flow then offers the cheapest across all of them.
 */
class ShippingRates
{
    public function __construct(private Container $app) {}

    /**
     * Merged rates from every enabled carrier. Never throws — a carrier that
     * fails simply contributes nothing.
     *
     * @return list<ShippingRate>
     */
    public function rates(Address $destination, int $totalWeightGrams): array
    {
        $rates = [];
        foreach ($this->carriers() as $carrier) {
            try {
                foreach ($carrier->rates($destination, $totalWeightGrams) as $rate) {
                    $rates[] = $rate;
                }
            } catch (\Throwable) {
                // one bad carrier shouldn't sink the whole quote
            }
        }

        return $rates;
    }

    /**
     * Enabled carrier instances, in configured order.
     *
     * @return list<ShippingCarrier>
     */
    public function carriers(): array
    {
        $registry = (array) config('services.shipping.registry', []);
        $enabled = config('services.shipping.carriers', ['canadapost']);
        $enabled = is_array($enabled) ? $enabled : explode(',', (string) $enabled);

        $carriers = [];
        foreach ($enabled as $key) {
            $key = trim((string) $key);
            if ($key !== '' && isset($registry[$key])) {
                $carriers[] = $this->app->make($registry[$key]);
            }
        }

        return $carriers;
    }
}
