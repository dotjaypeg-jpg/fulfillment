<?php

namespace Chargefield\Fulfillment\Data;

/** A provider's product catalogue (a family of blanks) — e.g. Gelato's "dad-hat" or "hoodies". */
final readonly class Catalog
{
    public function __construct(
        public string $uid,
        public string $title,
    ) {}
}
