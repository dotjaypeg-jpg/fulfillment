<?php

namespace Chargefield\Fulfillment\Data;

/**
 * The outcome of buying a shipping label, normalised across carriers. The label
 * itself is returned either as raw bytes ($contents) or a URL to fetch
 * ($labelUrl) — carriers differ. `raw` keeps the untouched provider payload.
 */
final readonly class LabelResult
{
    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public string $trackingNumber,
        public string $labelFormat = 'PDF',     // PDF, ZPL, …
        public ?string $contents = null,        // raw label bytes, when the carrier returns them
        public ?string $labelUrl = null,        // or a URL to fetch the label from
        public ?string $trackingUrl = null,
        public ?float $cost = null,
        public string $currency = 'CAD',
        public array $raw = [],
    ) {}
}
