<?php

namespace Chargefield\Fulfillment\Contracts;

use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\ShippingRate;

/**
 * A shipping carrier that can quote rates for self-shipped goods (items John
 * mails himself). Mirrors {@see PrintProvider} for print-on-demand: implement
 * this, register the class in config('services.shipping.registry'), and add its
 * key to config('services.shipping.carriers') to offer it. Rates from every
 * enabled carrier are pooled and the cheapest is offered at checkout.
 */
interface ShippingCarrier
{
    /** Stable identifier, e.g. 'canadapost', 'purolator'. */
    public function key(): string;

    /**
     * Live rates to a destination for a total parcel weight (grams). Should
     * degrade to [] on any error/misconfiguration rather than throw.
     *
     * @return list<ShippingRate>
     */
    public function rates(Address $destination, int $totalWeightGrams): array;
}
