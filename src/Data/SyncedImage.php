<?php

namespace Chargefield\Fulfillment\Data;

/**
 * A product mockup/preview image from the provider. `id` is the provider's stable
 * image id (used to name the downloaded file so re-syncs don't duplicate). `url`
 * may be a short-lived signed URL, so it must be downloaded, not hot-linked.
 */
final readonly class SyncedImage
{
    /** @param  list<string>  $variantExternalIds  provider variant ids this image belongs to */
    public function __construct(
        public string $id,
        public string $url,
        public bool $isPrimary = false,
        public array $variantExternalIds = [],
    ) {}
}
