<?php

namespace Chargefield\Fulfillment\Contracts;

use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\LabelResult;
use Chargefield\Fulfillment\Data\Parcel;
use Chargefield\Fulfillment\Exceptions\LabelException;

/**
 * A capability a {@see ShippingCarrier} MAY implement: buying a real shipping
 * label for a parcel and returning it (PDF + tracking). Kept separate from
 * ShippingCarrier because not every carrier can create labels — callers
 * feature-detect with `$carrier instanceof LabelCarrier` (see
 * {@see \Chargefield\Fulfillment\Shipping\ShippingRates::labelCarrier()}).
 *
 * Swapping carriers (Canada Post → Stallion Express) means implementing this one
 * method and registering the class — nothing in the calling code changes.
 *
 * Unlike rates(), which degrades to an empty list, createLabel() THROWS on any
 * failure: it's invoked by an admin who explicitly asked to buy a label and paid
 * for it, so a failure must surface, never be swallowed.
 */
interface LabelCarrier
{
    /** Stable carrier key (e.g. 'canadapost'), recorded against the shipment. */
    public function key(): string;

    /**
     * Buy a label to ship $parcel from $from to $to, optionally via a specific
     * carrier service code (else the carrier's sensible default).
     *
     * @throws LabelException on misconfiguration, an unsupported destination, or
     *                        any carrier/API failure.
     */
    public function createLabel(Address $from, Address $to, Parcel $parcel, ?string $service = null): LabelResult;
}
