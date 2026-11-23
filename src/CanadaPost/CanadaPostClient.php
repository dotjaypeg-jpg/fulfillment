<?php

namespace Chargefield\Fulfillment\CanadaPost;

use Chargefield\Fulfillment\Contracts\LabelCarrier;
use Chargefield\Fulfillment\Contracts\ShippingCarrier;
use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\LabelResult;
use Chargefield\Fulfillment\Data\Parcel;
use Chargefield\Fulfillment\Data\ShippingRate;
use Chargefield\Fulfillment\Exceptions\LabelException;
use Illuminate\Support\Facades\Http;

/**
 * Canada Post client for self-shipped items. Implements two capabilities:
 *  - {@see ShippingCarrier} — live rates (Rating API v4), degrades safely to a
 *    flat fallback when unconfigured.
 *  - {@see LabelCarrier} — buy a printable label (Create Non-Contract Shipment,
 *    NCShipment v4): a parcel label PDF + tracking PIN for a pay-as-you-go account
 *    with no parcel contract. Throws {@see LabelException} on any failure.
 *
 * Auth is HTTP Basic with a developer API username/password; requests/responses
 * are XML. See https://www.canadapost-postescanada.ca/information/app/drc/home
 */
class CanadaPostClient implements LabelCarrier, ShippingCarrier
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

    /**
     * Buy a Create Non-Contract Shipment (NCShipment v4) label: POST the shipment,
     * then GET the label PDF artifact (Canada Post renders it a beat later, so we
     * retry). Returns the PDF bytes + tracking PIN.
     *
     * Domestic (Canada) only for now — international shipments need customs data
     * this doesn't collect, so a non-CA destination throws rather than ship a bad
     * label. Throws {@see LabelException} on misconfiguration or any API failure.
     */
    public function createLabel(Address $from, Address $to, Parcel $parcel, ?string $service = null): LabelResult
    {
        if (! $this->labelConfigured()) {
            throw new LabelException('Canada Post labels need an API username, password and customer number.');
        }

        if (strtoupper($to->country) !== 'CA') {
            throw new LabelException('Canada Post label creation currently supports domestic (Canada) destinations only.');
        }

        $customer = (string) config('services.canadapost.customer_number');
        $base = rtrim((string) config('services.canadapost.endpoint'), '/');

        try {
            $response = $this->http()
                ->withHeaders([
                    'Content-Type' => 'application/vnd.cpc.ncshipment-v4+xml',
                    'Accept' => 'application/vnd.cpc.ncshipment-v4+xml',
                ])
                ->withBody(
                    $this->buildNcShipment($from, $to, $parcel, $service ?: 'DOM.EP'),
                    'application/vnd.cpc.ncshipment-v4+xml',
                )
                ->post("{$base}/rs/{$customer}/ncshipment");
        } catch (\Throwable $e) {
            throw new LabelException('Canada Post label request failed: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new LabelException(
                $this->messagesFrom($response->body()) ?? "Canada Post rejected the shipment (HTTP {$response->status()}).",
            );
        }

        [$trackingPin, $labelHref] = $this->parseNcShipment($response->body());
        if ($trackingPin === null || $labelHref === null) {
            throw new LabelException('Canada Post response carried no tracking PIN or label link.');
        }

        return new LabelResult(
            trackingNumber: $trackingPin,
            labelFormat: 'PDF',
            contents: $this->fetchArtifact($labelHref, $trackingPin),
            trackingUrl: 'https://www.canadapost-postescanada.ca/track-reperage/en#/search?searchFor='.$trackingPin,
            currency: 'CAD',
            raw: ['tracking_pin' => $trackingPin, 'label_href' => $labelHref],
        );
    }

    /** True when the account has the credentials a label call needs. */
    public function labelConfigured(): bool
    {
        foreach (['username', 'password', 'customer_number'] as $key) {
            if (blank(config("services.canadapost.{$key}"))) {
                return false;
            }
        }

        return true;
    }

    /** Basic-authed HTTP client for Canada Post calls. */
    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::withBasicAuth(
            (string) config('services.canadapost.username'),
            (string) config('services.canadapost.password'),
        )->withHeaders(['Accept-language' => 'en-CA']);
    }

    private function buildNcShipment(Address $from, Address $to, Parcel $parcel, string $service): string
    {
        $e = fn (?string $v): string => htmlspecialchars((string) $v, ENT_XML1);
        $line2 = fn (?string $v): string => filled($v) ? "<address-line-2>{$e($v)}</address-line-2>" : '';

        $weight = number_format($parcel->weightKg(), 3, '.', '');
        $dimensions = $parcel->hasDimensions()
            ? sprintf(
                '<dimensions><length>%s</length><width>%s</width><height>%s</height></dimensions>',
                number_format((float) $parcel->lengthCm, 1, '.', ''),
                number_format((float) $parcel->widthCm, 1, '.', ''),
                number_format((float) $parcel->heightCm, 1, '.', ''),
            )
            : '';

        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <non-contract-shipment xmlns="http://www.canadapost.ca/ws/ncshipment-v4">
          <requested-shipping-point>{$this->normalizePostal($from->postalCode)}</requested-shipping-point>
          <delivery-spec>
            <service-code>{$e($service)}</service-code>
            <sender>
              <name>{$e($from->name)}</name>
              <company>{$e($from->name)}</company>
              <contact-phone>{$e($from->phone)}</contact-phone>
              <address-details>
                <address-line-1>{$e($from->line1)}</address-line-1>
                {$line2($from->line2)}
                <city>{$e($from->city)}</city>
                <prov-state>{$e($from->state)}</prov-state>
                <postal-zip-code>{$this->normalizePostal($from->postalCode)}</postal-zip-code>
              </address-details>
            </sender>
            <destination>
              <name>{$e($to->name)}</name>
              <address-details>
                <address-line-1>{$e($to->line1)}</address-line-1>
                {$line2($to->line2)}
                <city>{$e($to->city)}</city>
                <prov-state>{$e($to->state)}</prov-state>
                <country-code>CA</country-code>
                <postal-zip-code>{$this->normalizePostal($to->postalCode)}</postal-zip-code>
              </address-details>
            </destination>
            <parcel-characteristics>
              <weight>{$weight}</weight>
              {$dimensions}
            </parcel-characteristics>
            <preferences>
              <show-packing-instructions>true</show-packing-instructions>
              <show-postage-rate>false</show-postage-rate>
              <show-insured-value>false</show-insured-value>
            </preferences>
          </delivery-spec>
        </non-contract-shipment>
        XML;
    }

    /**
     * Pull the tracking PIN and the label artifact URL out of an NCShipment
     * response.
     *
     * @return array{0: ?string, 1: ?string}  [trackingPin, labelHref]
     */
    private function parseNcShipment(string $xml): array
    {
        $doc = $this->loadXml($xml);
        if ($doc === null) {
            return [null, null];
        }

        $pin = trim((string) ($doc->{'tracking-pin'} ?? ''));

        $href = null;
        foreach ($doc->links->link ?? [] as $link) {
            if ((string) ($link['rel'] ?? '') === 'label') {
                $href = (string) ($link['href'] ?? '');
                break;
            }
        }

        return [$pin !== '' ? $pin : null, filled($href) ? $href : null];
    }

    /** Fetch the rendered label PDF, retrying for Canada Post's render delay. */
    private function fetchArtifact(string $href, string $trackingPin): string
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            if ($attempt > 0) {
                usleep(1_000_000); // ~1s — the PDF isn't ready immediately
            }

            try {
                $res = $this->http()->withHeaders(['Accept' => 'application/pdf'])->get($href);
                if ($res->successful() && $res->body() !== '') {
                    return $res->body();
                }
            } catch (\Throwable) {
                // fall through to retry
            }
        }

        throw new LabelException(
            "Canada Post created the shipment (tracking {$trackingPin}) but the label PDF wasn't ready; retrieve it from Canada Post.",
        );
    }

    /** Collapse a Canada Post `<messages>` error body into one line, if present. */
    private function messagesFrom(string $xml): ?string
    {
        $doc = $this->loadXml($xml);
        if ($doc === null) {
            return null;
        }

        $msgs = [];
        foreach ($doc->message ?? [] as $m) {
            $desc = trim((string) ($m->description ?? ''));
            if ($desc !== '') {
                $msgs[] = $desc;
            }
        }

        return $msgs === [] ? null : 'Canada Post: '.implode('; ', $msgs);
    }

    /** Parse an XML body with the default namespace stripped for plain-name reads. */
    private function loadXml(string $xml): ?\SimpleXMLElement
    {
        $xml = preg_replace('/xmlns(:\w+)?="[^"]*"/', '', $xml);

        $prev = libxml_use_internal_errors(true);
        $doc = simplexml_load_string((string) $xml);
        libxml_use_internal_errors($prev);

        return $doc === false ? null : $doc;
    }

    private function normalizePostal(string $postal): string
    {
        return htmlspecialchars(strtoupper(preg_replace('/\s+/', '', $postal) ?: ''), ENT_XML1);
    }
}
