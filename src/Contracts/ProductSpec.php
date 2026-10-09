<?php

namespace Chargefield\Fulfillment\Contracts;

/**
 * A capability a provider MAY implement: reporting what a blank currently IS —
 * paper weight, thickness, dimensions, fabric — so we can notice when a provider
 * quietly changes the stock under an existing product, or withdraws it.
 *
 * Costs are watched separately (PrintProvider::unitCost); this watches the goods.
 * A silent switch from 200 gsm to 170 gsm paper wouldn't move the price but would
 * make our published description wrong, so `costs:sync` snapshots this each run and
 * shouts when it drifts.
 *
 * Separate capability interface, like ProductCatalog/StoreSync: callers
 * feature-detect with `$driver instanceof ProductSpec`, so a provider without a
 * catalogue (e.g. self-fulfilled Manual) simply doesn't implement it.
 */
interface ProductSpec
{
    /**
     * A small, stable, comparable snapshot of the blank behind $providerUid —
     * keys sorted so two runs of the same unchanged product compare equal.
     *
     * Returns `['_missing' => true]` when the provider no longer knows the uid (the
     * product was withdrawn), and null when we can't tell (no credentials, transport
     * error) so callers never mistake "couldn't check" for "it changed".
     *
     * @return array<string,mixed>|null
     */
    public function productSpec(string $providerUid): ?array;
}
