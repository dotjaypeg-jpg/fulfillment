<?php

namespace Chargefield\Fulfillment\Data;

use Chargefield\Fulfillment\FulfillmentStatus;

/**
 * The outcome of creating or looking up a fulfilment order, normalised across
 * providers. `raw` keeps the untouched provider payload for debugging/audit.
 */
final readonly class FulfillmentResult
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public string $id,                  // provider order id
        public FulfillmentStatus $status,
        public ?string $trackingNumber = null,
        public ?string $trackingUrl = null,
        public array $raw = [],
    ) {}
}
