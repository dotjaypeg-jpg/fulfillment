<?php

namespace Chargefield\Fulfillment\Data;

/**
 * A shipping destination in provider-neutral terms. Providers translate this
 * into their own recipient shape (Gelato `recipient`, Printful `recipient`).
 */
final readonly class Address
{
    public function __construct(
        public string $name,
        public string $line1,
        public string $city,
        public string $postalCode,
        public string $country,     // ISO 3166-1 alpha-2, e.g. "CA"
        public ?string $line2 = null,
        public ?string $state = null,   // ISO/2-letter region code where applicable
        public ?string $email = null,
        public ?string $phone = null,
    ) {}
}
