<?php

namespace Chargefield\Fulfillment\Contracts;

use Chargefield\Fulfillment\Data\SyncedProduct;

/**
 * A capability a provider MAY implement: pulling the finished, designed products
 * from its connected store (Gelato's e-commerce API, Printful's store API, …) into
 * ours — title, mockup images, variants, fulfilment UIDs. This is the "the provider
 * pushes products to your store" workflow, done as a pull.
 *
 * Separate from PrintProvider/ProductCatalog (a distinct capability): callers
 * feature-detect with `$driver instanceof StoreSync`, so a provider without a store
 * sync simply doesn't implement it. Printful implements this alongside Gelato.
 */
interface StoreSync
{
    /**
     * Every designed product across the provider's connected store(s), fully
     * hydrated with variants and mockup images.
     *
     * @return iterable<SyncedProduct>
     */
    public function pullProducts(): iterable;
}
