<?php

namespace Chargefield\Fulfillment\Data;

/**
 * One concrete blank variant resolved from a catalogue selection: the provider's
 * productUid (→ ProductVariant::$provider_uid) plus the option values that identify
 * it, and shipping weight where the provider reports it. `cost` is left for a
 * separate pricing lookup (see PrintProvider::unitCost) to avoid a call per variant.
 */
final readonly class CatalogVariant
{
    /** @param  array<string, string>  $options  e.g. ['Size' => 'L/XL', 'Color' => 'Black'] */
    public function __construct(
        public string $providerUid,
        public array $options,
        public ?int $weightGrams = null,
        public ?float $cost = null,
    ) {}
}
