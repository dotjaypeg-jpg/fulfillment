<?php

namespace Chargefield\Fulfillment\Providers;

use Chargefield\Fulfillment\Contracts\PrintProvider;
use Chargefield\Fulfillment\Contracts\ProductCatalog;
use Chargefield\Fulfillment\Contracts\ProductSpec;
use Chargefield\Fulfillment\Contracts\StockAware;
use Chargefield\Fulfillment\Contracts\StoreSync;
use Chargefield\Fulfillment\Data\Address;
use Chargefield\Fulfillment\Data\Catalog;
use Chargefield\Fulfillment\Data\CatalogAttribute;
use Chargefield\Fulfillment\Data\CatalogVariant;
use Chargefield\Fulfillment\Data\FulfillmentResult;
use Chargefield\Fulfillment\Data\LineItem;
use Chargefield\Fulfillment\Data\ShippingRate;
use Chargefield\Fulfillment\Data\SyncedImage;
use Chargefield\Fulfillment\Data\SyncedProduct;
use Chargefield\Fulfillment\Data\SyncedVariant;
use Chargefield\Fulfillment\FulfillmentStatus;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Gelato (https://developers.gelato.com). Auth is an `X-API-KEY` header.
 *
 * Endpoint/field names below follow Gelato's v4 Order API; confirm against the
 * live docs when order creation is switched from draft to live in phase 3.
 */
class GelatoProvider implements PrintProvider, ProductCatalog, ProductSpec, StockAware, StoreSync
{
    private const ORDER_BASE = 'https://order.gelatoapis.com/v4';

    private const PRODUCT_BASE = 'https://product.gelatoapis.com/v3';

    private const ECOMMERCE_BASE = 'https://ecommerce.gelatoapis.com/v1';

    /**
     * ISO country -> Gelato stock region (US-CA, EU, OC, AS, SA, UK, ROW). Covers the
     * countries we let shoppers checkout to (see CheckoutController); anything else
     * falls through to ROW.
     */
    private const COUNTRY_REGION = [
        'US' => 'US-CA', 'CA' => 'US-CA',
        'GB' => 'UK',
        'AU' => 'OC', 'NZ' => 'OC',
        'DE' => 'EU', 'FR' => 'EU', 'NL' => 'EU', 'IT' => 'EU', 'ES' => 'EU',
        'SE' => 'EU', 'NO' => 'EU', 'DK' => 'EU', 'IE' => 'EU',
    ];

    /** Gelato stock statuses that mean we CAN fulfil (made-to-order included). */
    private const AVAILABLE_STATUSES = ['in-stock', 'non-stockable'];

    public function __construct(private string $apiKey) {}

    public function key(): string
    {
        return 'gelato';
    }

    public function shippingRates(Address $destination, array $items): array
    {
        $response = $this->client()
            ->post(self::ORDER_BASE.'/orders:quote', [
                'orderReferenceId' => 'quote-'.$destination->postalCode,
                'currency' => 'CAD',
                'recipient' => $this->recipient($destination),
                'products' => array_map(fn (LineItem $i, int $idx) => [
                    'itemReferenceId' => $i->reference ?? "item-{$idx}",
                    'productUid' => $i->sku,
                    'quantity' => $i->quantity,
                ], $items, array_keys($items)),
            ])
            ->throw()
            ->json();

        $rates = [];
        foreach ($response['quotes'] ?? [] as $quote) {
            foreach ($quote['shipmentMethods'] ?? [] as $method) {
                $uid = (string) ($method['shipmentMethodUid'] ?? $method['name'] ?? '');
                // Gelato returns a $0 placeholder method (e.g. api_out_of_stock_for_part_order)
                // when items can't be produced/shipped — not a real free-shipping option.
                if (str_contains(strtolower($uid), 'out_of_stock')) {
                    continue;
                }
                $rates[$method['shipmentMethodUid'] ?? $method['name']] = new ShippingRate(
                    id: (string) ($method['shipmentMethodUid'] ?? $method['name']),
                    name: (string) ($method['name'] ?? 'Shipping'),
                    amount: (float) ($method['price'] ?? 0),
                    currency: (string) ($method['currency'] ?? 'CAD'),
                    minDeliveryDays: $method['minDeliveryDays'] ?? null,
                    maxDeliveryDays: $method['maxDeliveryDays'] ?? null,
                );
            }
        }

        return array_values($rates);
    }

    public function createOrder(
        string $reference,
        Address $shipTo,
        array $items,
        ?string $shippingMethod = null,
        bool $draft = false,
    ): FulfillmentResult {
        $payload = [
            'orderType' => $draft ? 'draft' : 'order',
            'orderReferenceId' => $reference,
            'customerReferenceId' => $shipTo->email ?? $reference,
            'currency' => 'CAD',
            'shippingAddress' => $this->recipient($shipTo),
            'items' => array_map(fn (LineItem $i, int $idx) => array_filter([
                'itemReferenceId' => $i->reference ?? "item-{$idx}",
                'productUid' => $i->sku,
                'quantity' => $i->quantity,
                'files' => $this->orderFiles($i),
            ]), $items, array_keys($items)),
        ];

        if ($shippingMethod) {
            $payload['shipmentMethodUid'] = $shippingMethod;
        }

        $data = $this->client()->post(self::ORDER_BASE.'/orders', $payload)->throw()->json();

        return $this->toResult($data);
    }

    /**
     * The `files` payload for one item. An embroidery design Gelato has already
     * digitized is referenced by its file id (no re-upload, no repeat digitization
     * fee); otherwise we send the print file URL. Null when neither applies.
     *
     * @return list<array<string, string>>|null
     */
    private function orderFiles(LineItem $i): ?array
    {
        if ($i->embroideryFileId) {
            // Reference the stored digitized file. NOTE: confirm the exact key (`fileId`)
            // against the embroidery guide / the first order's response before going live
            // - verify with a draft (fulfillment:draft-test).
            return [['type' => $i->printType, 'fileId' => $i->embroideryFileId]];
        }

        return $i->printFileUrl ? [['type' => $i->printType, 'url' => $i->printFileUrl]] : null;
    }

    public function getOrder(string $providerOrderId): FulfillmentResult
    {
        $data = $this->client()->get(self::ORDER_BASE."/orders/{$providerOrderId}")->throw()->json();

        return $this->toResult($data);
    }

    public function unitCost(string $providerUid, string $country = 'CA', string $currency = 'CAD'): ?float
    {
        if ($this->apiKey === '') {
            return null;
        }

        // Gelato's productUid is the blank (type/size/colour), so this cost is shared
        // by every design printed on it. The Pricing API returns a list of price tiers;
        // the first entry is the single-unit price we pay.
        $prices = $this->client()
            ->get(self::PRODUCT_BASE."/products/{$providerUid}/prices", [
                'country' => $country,
                'currency' => $currency,
            ])
            ->throw()
            ->json();

        $cost = $prices[0]['price'] ?? null;

        return $cost === null ? null : round((float) $cost, 2);
    }

    /**
     * What Gelato currently says this blank IS. Weight and thickness are the tells
     * for a paper/fabric swap (e.g. 200 gsm = 27 g / 0.18 mm vs 170 gsm = 22.95 g /
     * 0.155 mm on the same sheet), so a silent stock change shows up here even when
     * the price doesn't move.
     */
    /**
     * Stock/region availability for a batch of UIDs, for one country. Made-to-order
     * (non-stockable) and in-stock both count as available; out-of-stock (incl.
     * replenishable), unavailable and not-supported do not. A UID the response omits
     * is left out of the result (callers treat missing as unknown = fail-open).
     *
     * @param  list<string>  $providerUids
     * @return array<string, bool>
     */
    public function availability(array $providerUids, string $country): array
    {
        $providerUids = array_values(array_unique(array_filter($providerUids)));
        if ($providerUids === [] || $this->apiKey === '') {
            return [];
        }

        $region = self::COUNTRY_REGION[strtoupper($country)] ?? 'ROW';
        $out = [];

        foreach (array_chunk($providerUids, 100) as $chunk) {
            // Short timeout + swallow errors: a slow/flaky stock API must never hang or
            // block a sale (fail-open) - the UID is simply left out of the result.
            try {
                $res = $this->client()->timeout(6)->post(self::PRODUCT_BASE.'/stock/region-availability', ['products' => $chunk]);
            } catch (\Throwable) {
                continue;
            }
            if (! $res->successful()) {
                continue;
            }

            foreach ($res->json('productsAvailability', []) as $p) {
                $uid = (string) ($p['productUid'] ?? '');
                if ($uid === '') {
                    continue;
                }
                // Available in this region = the region's status is in-stock / non-stockable.
                $available = false;
                foreach ($p['availability'] ?? [] as $a) {
                    if ((string) ($a['stockRegionUid'] ?? '') === $region) {
                        $available = in_array((string) ($a['status'] ?? ''), self::AVAILABLE_STATUSES, true);
                        break;
                    }
                }
                $out[$uid] = $available;
            }
        }

        return $out;
    }

    public function productSpec(string $providerUid): ?array
    {
        if ($this->apiKey === '') {
            return null;                       // can't tell — never report as a change
        }

        $res = $this->client()->get(self::PRODUCT_BASE."/products/{$providerUid}");

        if ($res->status() === 404) {
            return ['_missing' => true];       // withdrawn from the catalogue
        }

        if (! $res->successful()) {
            return null;
        }

        $body = $res->json();
        $dimensions = $body['dimensions'] ?? [];

        $spec = [
            'weight' => isset($body['weight']['value'])
                ? $body['weight']['value'].' '.($body['weight']['measureUnit'] ?? '')
                : null,
            'thickness' => isset($dimensions['Thickness']['value'])
                ? $dimensions['Thickness']['value'].' '.($dimensions['Thickness']['measureUnit'] ?? '')
                : null,
            'width' => isset($dimensions['Width']['value'])
                ? $dimensions['Width']['value'].' '.($dimensions['Width']['measureUnit'] ?? '')
                : null,
            'height' => isset($dimensions['Height']['value'])
                ? $dimensions['Height']['value'].' '.($dimensions['Height']['measureUnit'] ?? '')
                : null,
        ];

        // Any product attributes Gelato exposes (paper/coating/fabric…), flattened.
        foreach ($body['productAttributes'] ?? [] as $attr) {
            if (isset($attr['productAttributeUid'])) {
                $spec['attr:'.$attr['productAttributeUid']] = $attr['productAttributeValueUid'] ?? null;
            }
        }

        $spec = array_filter($spec, fn ($v) => $v !== null);
        ksort($spec);                          // stable ordering so runs compare equal

        return $spec ?: null;
    }

    public function catalogs(): array
    {
        $out = [];
        $offset = 0;
        $limit = 100;

        do {
            $data = $this->client()
                ->get(self::PRODUCT_BASE.'/catalogs', ['limit' => $limit, 'offset' => $offset])
                ->throw()
                ->json('data', []);

            foreach ($data as $c) {
                $out[] = new Catalog($c['catalogUid'], $c['title'] ?? $c['catalogUid']);
            }
            $offset += $limit;
        } while (count($data) === $limit);

        return $out;
    }

    public function attributes(string $catalogUid): array
    {
        $attributes = $this->client()
            ->get(self::PRODUCT_BASE."/catalogs/{$catalogUid}")
            ->throw()
            ->json('productAttributes', []);

        // Gelato returns ~12 attributes (State, ProductModel, …); only these are worth
        // picking when building a product, so the import form stays sensible.
        $pickable = ['GarmentSize', 'GarmentColor', 'GarmentQuality'];

        return array_values(array_map(fn (array $a) => new CatalogAttribute(
            key: $a['productAttributeUid'],
            label: $a['title'] ?? $a['productAttributeUid'],
            values: array_values(array_map(fn (array $v) => [
                'uid' => (string) $v['productAttributeValueUid'],
                'label' => (string) ($v['title'] ?? $v['productAttributeValueUid']),
            ], $a['values'] ?? [])),
        ), array_filter($attributes, fn ($a) => in_array($a['productAttributeUid'] ?? '', $pickable, true))));
    }

    public function variants(string $catalogUid, array $selection = []): array
    {
        $out = [];
        $offset = 0;
        $limit = 100;

        do {
            $body = ['limit' => $limit, 'offset' => $offset];
            if ($selection) {
                // Gelato expects each filter as a list of value uids.
                $body['attributeFilters'] = array_map(fn ($v) => (array) $v, $selection);
            }

            $products = $this->client()
                ->post(self::PRODUCT_BASE."/catalogs/{$catalogUid}/products:search", $body)
                ->throw()
                ->json('products', []);

            foreach ($products as $p) {
                $attr = $p['attributes'] ?? [];
                $out[] = new CatalogVariant(
                    providerUid: $p['productUid'],
                    options: array_filter([
                        'Size' => $attr['GarmentSize'] ?? null,
                        'Color' => $attr['GarmentColor'] ?? null,
                    ], fn ($v) => $v !== null),
                    weightGrams: isset($p['weight']['value']) ? (int) round((float) $p['weight']['value']) : null,
                );
            }
            $offset += $limit;
        } while (count($products) === $limit && $offset < 2000);   // safety cap on unfiltered searches

        return $out;
    }

    public function sizeGuide(string $productUid): ?array
    {
        // Gelato's catalogue exposes size *names* but no cm/inch measurement chart
        // (confirmed against the product endpoint), so there's nothing to auto-fill —
        // the merchant pastes a chart onto the Blank. Printful implements this fully.
        return null;
    }

    public function sizeGuideForVariant(string $variantUid): ?array
    {
        // Gelato's provider_uid already is the product uid, but it has no measurements.
        return $this->sizeGuide($variantUid);
    }

    public function pullProducts(): iterable
    {
        $stores = $this->client()->get(self::ECOMMERCE_BASE.'/stores')->throw()->json('stores', []);

        foreach ($stores as $store) {
            $offset = 0;
            $limit = 100;

            do {
                $page = $this->client()
                    ->get(self::ECOMMERCE_BASE."/stores/{$store['id']}/products", ['limit' => $limit, 'offset' => $offset])
                    ->throw()
                    ->json('products', []);

                foreach ($page as $summary) {
                    // Images + full variant options only come back on the detail call.
                    $detail = $this->client()
                        ->get(self::ECOMMERCE_BASE."/stores/{$store['id']}/products/{$summary['id']}")
                        ->throw()
                        ->json();

                    yield $this->toSyncedProduct($detail);
                }
                $offset += $limit;
            } while (count($page) === $limit);
        }
    }

    /** @param  array<string, mixed>  $p */
    private function toSyncedProduct(array $p): SyncedProduct
    {
        $axes = $p['productVariantOptions'] ?? [];   // [{name:'Color', values:[…]}, …]

        $variants = [];
        foreach ($p['variants'] ?? [] as $v) {
            if (empty($v['productUid'])) {
                continue;   // unmapped/draft variant — nothing to fulfil, skip
            }
            $variants[] = new SyncedVariant(
                providerUid: $v['productUid'],
                title: $v['title'] ?? '',
                options: $this->parseVariantOptions($v['title'] ?? '', $axes),
                externalId: (string) $v['id'],
            );
        }

        $images = array_values(array_map(fn (array $img) => new SyncedImage(
            id: (string) $img['id'],
            url: $img['fileUrl'],
            isPrimary: (bool) ($img['isPrimary'] ?? false),
            variantExternalIds: array_values(array_map('strval', $img['productVariantIds'] ?? [])),
        ), $p['productImages'] ?? []));

        return new SyncedProduct(
            externalId: (string) $p['id'],
            title: $p['title'] ?? 'Untitled',
            description: $p['description'] ?? null,
            handle: $p['handle'] ?? null,
            variants: $variants,
            images: $images,
            productType: $p['productType'] ?? null,
        );
    }

    /**
     * Gelato's e-commerce variant options live in its title ("Sand - XL - DTF …");
     * match each " - " part against the declared option axes to recover
     * ['Color' => 'Sand', 'Size' => 'XL'] (ignoring extras like the print method).
     *
     * @param  list<array{name:string, values:list<string>}>  $axes
     * @return array<string, string>
     */
    private function parseVariantOptions(string $title, array $axes): array
    {
        $parts = array_map('trim', explode(' - ', $title));
        $options = [];

        foreach ($axes as $axis) {
            foreach ($parts as $part) {
                if (in_array($part, $axis['values'], true)) {
                    $options[$axis['name']] = $part;
                    break;
                }
            }
        }

        return $options;
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders(['X-API-KEY' => $this->apiKey])
            ->acceptJson()
            ->asJson();
    }

    /** @return array<string, mixed> */
    private function recipient(Address $a): array
    {
        // Gelato's order API requires firstName + lastName (a single `name` is
        // rejected with "firstName is required"), so split our one name field.
        [$firstName, $lastName] = $this->splitName($a->name);

        return array_filter([
            'firstName' => $firstName,
            'lastName' => $lastName,
            'addressLine1' => $a->line1,
            'addressLine2' => $a->line2,
            'city' => $a->city,
            'postCode' => $a->postalCode,
            'state' => $a->state,
            'country' => $a->country,
            'email' => $a->email,
            'phone' => $a->phone,
        ], fn ($v) => $v !== null && $v !== '');
    }

    /**
     * Split one full name into first + last for Gelato. Splits on the first space;
     * a single-word name reuses it as the last name so neither is empty (Gelato
     * requires both).
     *
     * @return array{0: string, 1: string}
     */
    private function splitName(string $name): array
    {
        $name = trim($name);
        if ($name === '') {
            return ['Customer', 'Customer'];
        }

        $parts = preg_split('/\s+/', $name, 2) ?: [$name];

        return [$parts[0], $parts[1] ?? $parts[0]];
    }

    /** @param  array<string, mixed>  $data */
    private function toResult(array $data): FulfillmentResult
    {
        return new FulfillmentResult(
            id: (string) ($data['id'] ?? ''),
            status: $this->mapStatus($data['fulfillmentStatus'] ?? $data['orderType'] ?? null),
            trackingNumber: $data['shipment']['trackingCode'] ?? null,
            trackingUrl: $data['shipment']['trackingUrl'] ?? null,
            raw: $data,
        );
    }

    private function mapStatus(?string $status): FulfillmentStatus
    {
        return match ($status) {
            'draft' => FulfillmentStatus::Draft,
            'created', 'passed', 'pending' => FulfillmentStatus::Pending,
            'in_production', 'printed', 'printing' => FulfillmentStatus::InProduction,
            'shipped' => FulfillmentStatus::Shipped,
            'delivered' => FulfillmentStatus::Delivered,
            'canceled', 'cancelled' => FulfillmentStatus::Cancelled,
            'failed' => FulfillmentStatus::Failed,
            default => FulfillmentStatus::Unknown,
        };
    }
}
