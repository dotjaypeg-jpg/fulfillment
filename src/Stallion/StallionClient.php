<?php

namespace Chargefield\Fulfillment\Stallion;

use Chargefield\Fulfillment\Contracts\LabelCarrier;
use Chargefield\Fulfillment\Contracts\ShippingCarrier;
use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\LabelResult;
use Chargefield\Fulfillment\Data\Parcel;
use Chargefield\Fulfillment\Data\ShippingRate;
use Chargefield\Fulfillment\Exceptions\LabelException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Stallion Express (API v5) client for self-shipped items — a {@see ShippingCarrier}
 * (live rates) and {@see LabelCarrier} (buy a label). JSON over HTTPS, Bearer auth.
 * Registered alongside Canada Post: enabling it is a registry + env change and
 * nothing in the calling code moves.
 *
 * `POST /labels` creates the shipment and buys the label in one call, returning a
 * tracking number and a short-lived signed PDF link (downloaded here immediately).
 * Rates degrade to [] on any error; createLabel throws {@see LabelException} so a
 * failed buy surfaces to the admin.
 *
 * See https://docs.stallion.ca/openapi
 */
class StallionClient implements LabelCarrier, ShippingCarrier
{
    private const DEFAULT_ENDPOINT = 'https://ship.stallion.ca/api/v5';

    public function key(): string
    {
        return 'stallion';
    }

    /**
     * Live rates to a destination for a total parcel weight. The ship-from defaults
     * to the Stallion account's address, so none is sent.
     *
     * @return list<ShippingRate>
     */
    public function rates(Address $destination, int $totalWeightGrams): array
    {
        if (! $this->configured()) {
            return [];
        }

        try {
            $response = $this->http()->post($this->url('/rates'), [
                'to_address' => $this->address($destination),
                'packages' => [$this->package($totalWeightGrams)],
            ]);

            if (! $response->successful()) {
                return [];
            }

            return $this->parseRates((array) $response->json('data', []));
        } catch (\Throwable) {
            return [];
        }
    }

    public function createLabel(Address $from, Address $to, Parcel $parcel, ?string $service = null): LabelResult
    {
        if (! $this->configured()) {
            throw new LabelException('Stallion Express needs an API token.');
        }

        try {
            $response = $this->http()->post($this->url('/labels'), array_filter([
                'from_address' => $this->address($from),
                'to_address' => $this->address($to),
                'packages' => [$this->package($parcel->weightGrams, $parcel)],
                'service' => $service,       // null → Stallion's default cheapest_tracked
                'label_format' => 'pdf',
            ], fn ($v) => $v !== null));
        } catch (\Throwable $e) {
            throw new LabelException('Stallion Express label request failed: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            throw new LabelException(
                $this->errorFrom((array) $response->json()) ?? "Stallion Express rejected the shipment (HTTP {$response->status()}).",
            );
        }

        // The POST /labels schema returns fields at the top level; tolerate a
        // data-wrapped body too, and tracking_number vs tracking_code.
        $body = (array) $response->json();
        $data = isset($body['data']) && is_array($body['data']) ? $body['data'] : $body;

        $tracking = $data['tracking_number'] ?? $data['tracking_code'] ?? null;
        $labelUrl = $data['label_url'] ?? null;
        if (! is_string($tracking) || $tracking === '' || ! is_string($labelUrl) || $labelUrl === '') {
            throw new LabelException('Stallion Express response carried no tracking number or label URL.');
        }

        $rate = (array) ($data['rate'] ?? []);

        return new LabelResult(
            trackingNumber: $tracking,
            labelFormat: 'PDF',
            // The signed label URL expires in ~30 minutes, so fetch the PDF now.
            contents: $this->fetchLabel($labelUrl),
            labelUrl: $labelUrl,
            cost: isset($rate['total']) ? (float) $rate['total'] : null,
            currency: (string) ($rate['currency'] ?? 'CAD'),
            raw: $data,
        );
    }

    /** True when an API token is configured. */
    public function configured(): bool
    {
        return filled(config('services.stallion.token'));
    }

    private function http(): PendingRequest
    {
        return Http::withToken((string) config('services.stallion.token'))->acceptJson();
    }

    private function url(string $path): string
    {
        return rtrim((string) config('services.stallion.endpoint', self::DEFAULT_ENDPOINT), '/').$path;
    }

    /** @return array<string, mixed> */
    private function address(Address $a): array
    {
        return array_filter([
            'name' => $a->name,
            'address1' => $a->line1,
            'address2' => $a->line2,
            'city' => $a->city,
            'province_code' => $a->state,
            'postal_code' => $a->postalCode,
            'country_code' => strtoupper($a->country),
            'phone' => $a->phone,
            'email' => $a->email,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /** @return array<string, mixed> */
    private function package(int $weightGrams, ?Parcel $parcel = null): array
    {
        $package = ['weight' => max(1, $weightGrams), 'weight_unit' => 'g'];

        if ($parcel?->hasDimensions()) {
            $package += [
                'length' => $parcel->lengthCm,
                'width' => $parcel->widthCm,
                'height' => $parcel->heightCm,
                'size_unit' => 'cm',
            ];
        }

        return $package;
    }

    /**
     * @param  array<int, mixed>  $rates  rate objects straight from the JSON response
     * @return list<ShippingRate>
     */
    private function parseRates(array $rates): array
    {
        $out = [];
        foreach ($rates as $rate) {
            if (! is_array($rate) || ! isset($rate['total'])) {
                continue;
            }
            $name = trim((string) ($rate['carrier'] ?? '').' '.(string) ($rate['service_name'] ?? ''));
            $out[] = new ShippingRate(
                id: (string) ($rate['service'] ?? 'stallion'),
                name: $name !== '' ? $name : 'Stallion Express',
                amount: round((float) $rate['total'], 2),
                currency: (string) ($rate['currency'] ?? 'CAD'),
                maxDeliveryDays: isset($rate['estimated_delivery_days']) ? (int) $rate['estimated_delivery_days'] : null,
            );
        }

        return $out;
    }

    /** Download the signed label PDF (no auth header — the URL is pre-signed). */
    private function fetchLabel(string $url): string
    {
        try {
            $response = Http::get($url);
            if ($response->successful() && $response->body() !== '') {
                return $response->body();
            }
        } catch (\Throwable) {
            // fall through to the throw below
        }

        throw new LabelException('Stallion Express returned a label link that could not be downloaded.');
    }

    /** @param  array<string, mixed>  $body */
    private function errorFrom(array $body): ?string
    {
        $message = $body['error']['message'] ?? null;

        return is_string($message) && $message !== '' ? 'Stallion Express: '.$message : null;
    }
}
