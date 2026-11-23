<?php

namespace Chargefield\Fulfillment\Shipping;

use Chargefield\Fulfillment\Contracts\LabelCarrier;
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

    /**
     * The first enabled carrier that can buy labels, or null when none can.
     * Same registry/config seam as rates() — enabling a label-capable carrier
     * (Canada Post, Stallion) is all it takes for the "buy a label" flow to work.
     */
    public function labelCarrier(): ?LabelCarrier
    {
        foreach ($this->carriers() as $carrier) {
            if ($carrier instanceof LabelCarrier) {
                return $carrier;
            }
        }

        return null;
    }

    /**
     * The label-capable carrier offering the cheapest rate to $destination for
     * $totalWeightGrams — quote each, compare their lowest option, pick the winner.
     * A carrier that can't quote (unconfigured, or a transient error — rates()
     * returns []) is skipped. Returns null when none can quote, so the caller can
     * fall back to {@see labelCarrier()}.
     */
    public function cheapestLabelCarrier(Address $destination, int $totalWeightGrams): ?LabelCarrier
    {
        $best = null;
        $bestAmount = null;

        foreach ($this->carriers() as $carrier) {
            if (! $carrier instanceof LabelCarrier) {
                continue;
            }

            $cheapest = null;
            foreach ($carrier->rates($destination, $totalWeightGrams) as $rate) {
                if ($cheapest === null || $rate->amount < $cheapest) {
                    $cheapest = $rate->amount;
                }
            }

            if ($cheapest !== null && ($bestAmount === null || $cheapest < $bestAmount)) {
                $bestAmount = $cheapest;
                $best = $carrier;
            }
        }

        return $best;
    }
}
