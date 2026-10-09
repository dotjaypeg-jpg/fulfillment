<?php

namespace Chargefield\Fulfillment\Data;

/**
 * A finished, designed product pulled from a provider's connected store (Gelato's
 * e-commerce API, Printful's store API, …) — title, mockup images, and variants —
 * ready to create/update as a local Product. See Chargefield\Fulfillment\Contracts\StoreSync.
 */
final readonly class SyncedProduct
{
    /**
     * @param  list<SyncedVariant>  $variants
     * @param  list<SyncedImage>  $images
     */
    public function __construct(
        public string $externalId,
        public string $title,
        public ?string $description,
        public ?string $handle,
        public array $variants,
        public array $images,
        public ?string $productType = null,
    ) {}
}
