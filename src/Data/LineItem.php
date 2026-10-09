<?php

namespace Chargefield\Fulfillment\Data;

/**
 * One line to be printed and shipped.
 *
 * `sku` is the *provider's* product identifier — a Gelato product UID, a
 * Printful sync/variant id, etc. Because the same catalogue product has a
 * different id per provider, callers supply the id for the active provider
 * (typically via a per-provider mapping). Our stored OrderItem->sku is the
 * Gelato UID today.
 *
 * `printFileUrl` is the artwork the provider prints; required to create an
 * order but not to quote shipping.
 *
 * `weightGrams` is the physical shipping weight of ONE unit; only self-shipped
 * (Canada Post) rating uses it — print-on-demand providers ignore it.
 *
 * `embroideryFileId` references a design the provider has ALREADY digitized (Gelato
 * returns a file id on the first embroidery order). When set, the provider references
 * that stored digitized file instead of re-uploading `printFileUrl` — so re-orders of
 * the same embroidered design aren't digitized (or charged) again.
 */
final readonly class LineItem
{
    public function __construct(
        public string $sku,
        public int $quantity,
        public ?string $printFileUrl = null,
        public ?string $reference = null,   // our own OrderItem id / title, echoed back for reconciliation
        public ?int $weightGrams = null,
        public string $printType = 'default', // Gelato print-area file type: default | front | back
        public ?string $embroideryFileId = null,
    ) {}
}
