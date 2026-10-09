<?php

namespace Chargefield\Fulfillment\Support;

/**
 * A representative destination postal code per country, used to get a shipping
 * *estimate* before we know the shopper's exact address. Gelato's North-American
 * carriers (Canada, US) won't return a rate without a postal code — the cart only
 * knows the country — so we substitute a central metro code. The exact address is
 * still collected at Stripe checkout.
 */
class RegionPostal
{
    private const CODES = [
        'CA' => 'M5V 3L9',   // Toronto
        'US' => '10001',     // New York
        'GB' => 'SW1A 1AA',  // London
        'AU' => '2000',      // Sydney
        'NZ' => '6011',      // Wellington
        'IE' => 'D01 F5P2',  // Dublin
        'DE' => '10115',     // Berlin
        'FR' => '75001',     // Paris
        'NL' => '1011 AC',   // Amsterdam
        'IT' => '00184',     // Rome
        'ES' => '28001',     // Madrid
        'SE' => '111 29',    // Stockholm
        'NO' => '0150',      // Oslo
        'DK' => '1050',      // Copenhagen
    ];

    public static function for(string $country): string
    {
        return self::CODES[strtoupper($country)] ?? '';
    }
}
