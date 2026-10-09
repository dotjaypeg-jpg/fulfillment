<?php

namespace Chargefield\Fulfillment\Contracts;

/**
 * A capability a print provider MAY implement: reporting whether its products can be
 * produced + shipped to a destination right now, so we don't sell what the provider
 * can't fulfil in time (a real past cause of a cancelled order + refund). Kept separate
 * from PrintProvider because not every fulfiller exposes stock (self-shipped/Manual is
 * always available) — callers feature-detect with `$driver instanceof StockAware`.
 *
 * Deliberately country-based, not provider-region based: the caller passes a 2-letter
 * country and each provider maps it to its own stock regions, so switching/adding a
 * provider (Printful) means implementing this one method, nothing in the calling code.
 */
interface StockAware
{
    /**
     * Which of these provider UIDs can be produced AND delivered to $country now.
     * Made-to-order items (no held stock) count as available. A UID the provider
     * doesn't recognise counts as unavailable. UIDs absent from the result are treated
     * as unknown by callers (fail-open, so a flaky stock API never blocks checkout).
     *
     * @param  list<string>  $providerUids
     * @param  string  $country  ISO 3166-1 alpha-2 (e.g. "CA")
     * @return array<string, bool>  providerUid => available
     */
    public function availability(array $providerUids, string $country): array;
}
