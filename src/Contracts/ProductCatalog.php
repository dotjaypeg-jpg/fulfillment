<?php

namespace Chargefield\Fulfillment\Contracts;

use Chargefield\Fulfillment\Data\Catalog;
use Chargefield\Fulfillment\Data\CatalogAttribute;
use Chargefield\Fulfillment\Data\CatalogVariant;

/**
 * A capability a print provider MAY implement: browsing its product catalogue so a
 * new product can be created in the CMS by picking a blank instead of hand-entering
 * every variant + productUid. Kept separate from PrintProvider because not every
 * fulfiller has a catalogue (self-shipped/Manual doesn't) — callers feature-detect
 * with `$driver instanceof ProductCatalog`. Printful implements this alongside
 * Gelato and additionally fills sizeGuide().
 */
interface ProductCatalog
{
    /**
     * Top-level catalogues (blank families) available on this provider.
     *
     * @return list<Catalog>
     */
    public function catalogs(): array;

    /**
     * The attributes (size, colour, quality, …) and their values for one catalogue,
     * used to build the selection form.
     *
     * @return list<CatalogAttribute>
     */
    public function attributes(string $catalogUid): array;

    /**
     * The concrete blank variants matching an attribute selection — each carries the
     * providerUid and option values. Callers should pass a selection to keep large
     * catalogues bounded.
     *
     * @param  array<string, string|list<string>>  $selection  attribute key => chosen value uid(s)
     * @return list<CatalogVariant>
     */
    public function variants(string $catalogUid, array $selection = []): array;

    /**
     * A structured size guide for a blank, when the provider publishes measurements.
     * Returns null when it doesn't (Gelato) — the merchant pastes one on the Blank.
     *
     * @return array{sizes: list<string>, rows: list<array{label:string, cm:list<string>, inch:list<string>}>}|null
     */
    public function sizeGuide(string $productUid): ?array;

    /**
     * The size guide for the blank behind one of our stored variant UIDs — providers
     * resolve their own variant→product mapping. Used to build a Blank from an existing
     * product. Returns null when the provider has no measurements.
     *
     * @return array{sizes: list<string>, rows: list<array{label:string, cm:list<string>, inch:list<string>}>}|null
     */
    public function sizeGuideForVariant(string $variantUid): ?array;
}
