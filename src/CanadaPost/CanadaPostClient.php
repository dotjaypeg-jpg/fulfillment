<?php

namespace Chargefield\Fulfillment\CanadaPost;

use Chargefield\Fulfillment\Contracts\ShippingCarrier;
use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\ShippingRate;
use Illuminate\Support\Facades\Http;

/**
 * Canada Post "Get Rates" (Rating API v4) client for self-shipped items — one
 * {@see ShippingCarrier}.
 *
 * Auth is HTTP Basic with a developer API username/password; the request/response
 * are XML (application/vnd.cpc.ship.rate-v4+xml). See
 * https://www.canadapost-postescanada.ca/information/app/drc/home
 *
 * Degrades safely: if credentials/origin aren't configured it makes NO network
 * call and returns the optional flat fallback rate (or nothing), so the store
 * keeps working before a Canada Post contract is wired up.
 */
class CanadaPostClient implements ShippingCarrier
{
    public function key(): string
    {
        return 'canadapost';
    }

    /**
     * Live rates to a destination for a total parcel weight.
     *
     * @return list<ShippingRate>
     */
    public function rates(Address $destination, int $totalWeightGrams): array
    {
        if (! $this->configured()) {
            return $this->fallback();
        }

        try {
            $body = $this->buildScenario($destination, $totalWeightGrams);

            $response = Http::withBasicAuth(
                (string) config('services.canadapost.username'),
                (string) config('services.canadapost.password'),
            )
                ->withHeaders([
                    'Content-Type' => 'application/vnd.cpc.ship.rate-v4+xml',
                    'Accept' => 'application/vnd.cpc.ship.rate-v4+xml',
                    'Accept-language' => 'en-CA',
                ])
                ->withBody($body, 'application/vnd.cpc.ship.rate-v4+xml')
                ->post(rtrim((string) config('services.canadapost.endpoint'), '/').'/rs/ship/price');

            if (! $response->successful()) {
                return $this->fallback();
            }

            $rates = $this->parse($response->body());

            return $rates !== [] ? $rates : $this->fallback();
        } catch (\Throwable) {
            return $this->fallback();
        }
    }

    /** True when the account is fully configured for a live rate call. */
    public function configured(): bool
    {
        foreach (['username', 'password', 'customer_number', 'origin_postal_code'] as $key) {
            if (blank(config("services.canadapost.{$key}"))) {
                return false;
            }
        }

        return true;
    }

    /** @return list<ShippingRate> */
    private function fallback(): array
    {
        $flat = config('services.canadapost.fallback_rate');
        if ($flat === null) {
            return [];
        }

        return [new ShippingRate(
            id: 'flat',
            name: 'Standard Shipping',
            amount: (float) $flat,
            currency: 'CAD',
        )];
    }

    private function buildScenario(Address $destination, int $totalWeightGrams): string
    {
        // Canada Post wants kilograms; clamp to a sane minimum (1g).
        $kg = number_format(max(1, $totalWeightGrams) / 1000, 3, '.', '');
        $customer = htmlspecialchars((string) config('services.canadapost.customer_number'), ENT_XML1);
        $origin = $this->normalizePostal((string) config('services.canadapost.origin_postal_code'));

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <mailing-scenario xmlns="http://www.canadapost.ca/ws/ship/rate-v4">
          <customer-number>{$customer}</customer-number>
          <parcel-characteristics>
            <weight>{$kg}</weight>
          </parcel-characteristics>
          <origin-postal-code>{$origin}</origin-postal-code>
          <destination>{$this->destinationXml($destination)}</destination>
        </mailing-scenario>
        XML;
    }

    private function destinationXml(Address $d): string
    {
        $country = strtoupper($d->country);

        if ($country === 'CA') {
            $postal = $this->normalizePostal($d->postalCode);

            return "<domestic><postal-code>{$postal}</postal-code></domestic>";
        }

        if ($country === 'US') {
            $zip = htmlspecialchars(substr(preg_replace('/[^0-9]/', '', $d->postalCode) ?: '', 0, 5), ENT_XML1);

            return "<united-states><zip-code>{$zip}</zip-code></united-states>";
        }

        $cc = htmlspecialchars($country, ENT_XML1);

        return "<international><country-code>{$cc}</country-code></international>";
    }

    /** @return list<ShippingRate> */
    private function parse(string $xml): array
    {
        // Canada Post responses carry a default namespace; strip it so children
        // can be read by plain name.
        $xml = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $xml);

        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml);
        libxml_use_internal_errors($prev);

        if ($doc === false) {
            return [];
        }

        $rates = [];
        foreach ($doc->{'price-quote'} ?? [] as $q) {
            $due = (float) ($q->{'price-details'}->due ?? 0);
            if ($due <= 0) {
                continue;
            }
            $transit = $q->{'service-standard'}->{'expected-transit-time'} ?? null;
            $rates[] = new ShippingRate(
                id: (string) ($q->{'service-code'} ?? 'cp'),
                name: (string) ($q->{'service-name'} ?? 'Canada Post'),
                amount: round($due, 2),
                currency: 'CAD',
                maxDeliveryDays: $transit !== null ? (int) $transit : null,
            );
        }

        return $rates;
    }

    private function normalizePostal(string $postal): string
    {
        return htmlspecialchars(strtoupper(preg_replace('/\s+/', '', $postal) ?: ''), ENT_XML1);
    }
}
