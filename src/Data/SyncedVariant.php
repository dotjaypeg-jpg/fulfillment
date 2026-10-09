<?php

namespace Chargefield\Fulfillment\Data;

/** One variant of a synced product: its provider fulfilment UID, option values and the provider's variant id. */
final readonly class SyncedVariant
{
    /** @param  array<string, string>  $options  e.g. ['Color' => 'Sand', 'Size' => 'XL'] */
    public function __construct(
        public string $providerUid,
        public string $title,
        public array $options,
        public string $externalId,
        public ?int $weightGrams = null,
        // The retail price set at the provider, used as the variant's base price when
        // no price-book rule/override applies. Null when the provider doesn't report one.
        public ?float $price = null,
    ) {}
}
