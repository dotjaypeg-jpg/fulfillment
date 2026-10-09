<?php

namespace Chargefield\Fulfillment\Data;

/**
 * A choice a shopper/merchant makes within a catalogue — e.g. "GarmentSize" with
 * values S/M/L, or "GarmentColor" with black/white. Used to build the import form.
 */
final readonly class CatalogAttribute
{
    /** @param  list<array{uid: string, label: string}>  $values */
    public function __construct(
        public string $key,
        public string $label,
        public array $values,
    ) {}
}
