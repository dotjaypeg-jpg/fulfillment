<?php

namespace Chargefield\Fulfillment\Contracts;

use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\FulfillmentResult;
use Chargefield\Fulfillment\Data\LineItem;
use Chargefield\Fulfillment\Data\ShippingRate;

/**
 * Contract every print-on-demand provider (Gelato, Printful, …) implements.
 *
 * Calling code depends only on this interface and the neutral DTOs in
 * Chargefield\Fulfillment\Data, so switching providers is a config change — see
 * Chargefield\Fulfillment\FulfillmentManager.
 */
interface PrintProvider
{
    /** Stable machine key for this provider, e.g. "gelato", "printful". */
    public function key(): string;

    /**
     * Available shipping options for delivering the given items to a destination.
     *
     * @param  list<LineItem>  $items
     * @return list<ShippingRate>
     */
    public function shippingRates(Address $destination, array $items): array;

    /**
     * Submit an order for production and shipping.
     *
     * @param  string  $reference  Our order number, echoed back by the provider.
     * @param  list<LineItem>  $items  Each needs a printFileUrl.
     * @param  string|null  $shippingMethod  A ShippingRate::$id from shippingRates().
     * @param  bool  $draft  When true, create a non-committing draft (no charge/print).
     */
    public function createOrder(
        string $reference,
        Address $shipTo,
        array $items,
        ?string $shippingMethod = null,
        bool $draft = false,
    ): FulfillmentResult;

    /** Current status + tracking for a previously submitted order. */
    public function getOrder(string $providerOrderId): FulfillmentResult;

    /**
     * The per-unit cost this provider charges us for a catalogue item — used to
     * populate product_variants.cost so the CMS can warn when a sell price falls
     * below cost.
     *
     * @param  string  $providerUid  The provider's own product id (Gelato UID, Printful variant id, …), i.e. ProductVariant::$provider_uid.
     * @return float|null Null when this provider has no priced catalogue for the item (e.g. self-fulfilled goods) or isn't configured.
     */
    public function unitCost(string $providerUid, string $country = 'CA', string $currency = 'CAD'): ?float;
}
