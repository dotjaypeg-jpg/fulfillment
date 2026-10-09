<?php

namespace Chargefield\Fulfillment\Providers;

use Chargefield\Fulfillment\Contracts\PrintProvider;
use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\FulfillmentResult;
use Chargefield\Fulfillment\Data\LineItem;
use Chargefield\Fulfillment\FulfillmentStatus;
use Chargefield\Fulfillment\Shipping\ShippingRates;

/**
 * Self-fulfilled goods (e.g. hand screen-printed shirts) — no print API. John
 * ships these himself, so order creation stays offline (never calls a service),
 * but shipping is quoted live across the enabled carriers (Canada Post, etc.)
 * using each item's weight.
 */
class ManualProvider implements PrintProvider
{
    public function __construct(private ShippingRates $shipping) {}

    public function key(): string
    {
        return 'manual';
    }

    public function shippingRates(Address $destination, array $items): array
    {
        return $this->shipping->rates($destination, $this->totalWeightGrams($items));
    }

    public function createOrder(
        string $reference,
        Address $shipTo,
        array $items,
        ?string $shippingMethod = null,
        bool $draft = false,
    ): FulfillmentResult {
        return new FulfillmentResult(
            id: 'manual-'.$reference,
            status: FulfillmentStatus::Pending,
            raw: ['manual' => true, 'items' => count($items)],
        );
    }

    public function getOrder(string $providerOrderId): FulfillmentResult
    {
        return new FulfillmentResult(id: $providerOrderId, status: FulfillmentStatus::Pending, raw: ['manual' => true]);
    }

    /** No catalogue/API — John sets these costs by hand in the CMS, so nothing to fetch. */
    public function unitCost(string $providerUid, string $country = 'CA', string $currency = 'CAD'): ?float
    {
        return null;
    }

    /** @param  list<LineItem>  $items */
    private function totalWeightGrams(array $items): int
    {
        $default = (int) config('services.canadapost.default_weight_grams', 500);
        $total = 0;
        foreach ($items as $item) {
            $total += ($item->weightGrams ?? $default) * max(1, $item->quantity);
        }

        return $total;
    }
}
