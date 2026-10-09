<?php

namespace Chargefield\Fulfillment\Providers;

use Chargefield\Fulfillment\Contracts\PrintProvider;
use Chargefield\Fulfillment\Contracts\ProductCatalog;
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
use Illuminate\Support\Str;

/**
 * Printful (https://developers.printful.com). Auth is a Bearer token, with an
 * optional `X-PF-Store-Id` header. Responses wrap their payload in `result`.
 *
 * Note the SKU difference: Printful items are keyed by `sync_variant_id`, not
 * the Gelato product UID — so LineItem::$sku must carry the Printful id when
 * this driver is active (a per-provider catalogue mapping, not wired yet).
 *
 * Catalogue model differs from Gelato: a Printful "catalog product" (id 71 =
 * Bella+Canvas 3001 tee) IS the blank family, and its variants are the size/colour
 * combos — so we map one Printful product to one Catalog. Unlike Gelato, Printful
 * exposes real measurement charts, so sizeGuide() is populated.
 */
class PrintfulProvider implements PrintProvider, ProductCatalog, StoreSync
{
    private const BASE = 'https://api.printful.com';

    public function __construct(private string $apiKey, private ?string $storeId = null) {}

    public function key(): string
    {
        return 'printful';
    }

    public function shippingRates(Address $destination, array $items): array
    {
        $result = $this->client()
            ->post(self::BASE.'/shipping/rates', [
                'recipient' => $this->recipient($destination),
                'items' => array_map(fn (LineItem $i) => [
                    'sync_variant_id' => $i->sku,
                    'quantity' => $i->quantity,
                ], $items),
            ])
            ->throw()
            ->json('result', []);

        return array_values(array_map(fn (array $rate) => new ShippingRate(
            id: (string) $rate['id'],
            name: (string) ($rate['name'] ?? $rate['id']),
            amount: (float) ($rate['rate'] ?? 0),
            currency: (string) ($rate['currency'] ?? 'USD'),
            minDeliveryDays: $rate['minDeliveryDays'] ?? null,
            maxDeliveryDays: $rate['maxDeliveryDays'] ?? null,
        ), $result));
    }

    public function createOrder(
        string $reference,
        Address $shipTo,
        array $items,
        ?string $shippingMethod = null,
        bool $draft = false,
    ): FulfillmentResult {
        $data = $this->client()
            // confirm=false leaves the order as a draft (no charge, no print).
            ->post(self::BASE.'/orders?confirm='.($draft ? 'false' : 'true'), array_filter([
                'external_id' => $reference,
                'shipping' => $shippingMethod,
                'recipient' => $this->recipient($shipTo),
                'items' => array_map(fn (LineItem $i) => array_filter([
                    'sync_variant_id' => $i->sku,
                    'quantity' => $i->quantity,
                    'files' => $i->printFileUrl ? [['url' => $i->printFileUrl]] : null,
                ]), $items),
            ]))
            ->throw()
            ->json('result', []);

        return $this->toResult($data);
    }

    public function getOrder(string $providerOrderId): FulfillmentResult
    {
        $data = $this->client()->get(self::BASE."/orders/{$providerOrderId}")->throw()->json('result', []);

        return $this->toResult($data);
    }

    public function unitCost(string $providerUid, string $country = 'CA', string $currency = 'CAD'): ?float
    {
        if ($this->apiKey === '') {
            return null;
        }

        // Base print price comes from the *catalogue* variant, so $providerUid must be
        // the Printful catalog variant id (not the sync_variant_id used for orders — see
        // the SKU note on the class). `result.variant.price` is a string like "16.95".
        $price = $this->client()
            ->get(self::BASE."/products/variant/{$providerUid}")
            ->throw()
            ->json('result.variant.price');

        return $price === null ? null : round((float) $price, 2);
    }

    public function catalogs(): array
    {
        $out = [];
        $offset = 0;
        $limit = 100;

        do {
            $result = $this->client()
                ->get(self::BASE.'/products', ['limit' => $limit, 'offset' => $offset])
                ->throw()
                ->json('result', []);

            foreach ($result as $p) {
                $out[] = new Catalog((string) $p['id'], $p['title'] ?? ('Product '.$p['id']));
            }
            $offset += $limit;
        } while (count($result) === $limit);

        return $out;
    }

    public function attributes(string $catalogUid): array
    {
        $variants = $this->productVariants($catalogUid);

        $sizes = $colors = [];
        foreach ($variants as $v) {
            if (! empty($v['size'])) {
                $sizes[$v['size']] = true;
            }
            if (! empty($v['color'])) {
                $colors[$v['color']] = true;
            }
        }

        $toAttr = fn (string $key, string $label, array $vals) => new CatalogAttribute(
            $key, $label, array_map(fn ($v) => ['uid' => $v, 'label' => $v], array_keys($vals)),
        );

        return array_values(array_filter([
            $sizes ? $toAttr('size', 'Size', $sizes) : null,
            $colors ? $toAttr('color', 'Color', $colors) : null,
        ]));
    }

    public function variants(string $catalogUid, array $selection = []): array
    {
        $wantSize = (array) ($selection['size'] ?? []);
        $wantColor = (array) ($selection['color'] ?? []);

        $out = [];
        foreach ($this->productVariants($catalogUid) as $v) {
            if ($wantSize && ! in_array($v['size'] ?? null, $wantSize, true)) {
                continue;
            }
            if ($wantColor && ! in_array($v['color'] ?? null, $wantColor, true)) {
                continue;
            }

            $out[] = new CatalogVariant(
                providerUid: (string) $v['id'],
                options: array_filter(['Size' => $v['size'] ?? null, 'Color' => $v['color'] ?? null], fn ($x) => $x !== null),
                weightGrams: null,
                // Printful's catalogue variant carries the merchant price directly.
                cost: isset($v['price']) ? round((float) $v['price'], 2) : null,
            );
        }

        return $out;
    }

    public function sizeGuide(string $productUid): ?array
    {
        // Printful publishes real measurement tables — fetch cm + inch and merge.
        $cm = $this->client()->get(self::BASE."/products/{$productUid}/sizes", ['unit' => 'cm'])->throw()->json('result', []);
        $inch = $this->client()->get(self::BASE."/products/{$productUid}/sizes", ['unit' => 'inches'])->json('result', []);

        $sizes = $cm['available_sizes'] ?? [];
        $cmRows = $this->measurementRows($cm);
        if (! $sizes || ! $cmRows) {
            return null;
        }
        $inchRows = $this->measurementRows($inch);

        $rows = [];
        foreach ($cmRows as $label => $cmBySize) {
            $rows[] = [
                'label' => $label,
                'cm' => array_values(array_map(fn ($s) => $this->fmt($cmBySize[$s] ?? null), $sizes)),
                'inch' => array_values(array_map(fn ($s) => $this->fmt($inchRows[$label][$s] ?? null), $sizes)),
            ];
        }

        return ['sizes' => array_values($sizes), 'rows' => $rows];
    }

    public function pullProducts(): iterable
    {
        // Account-level token → store endpoints need an X-PF-Store-Id. Use the configured
        // store, else every store on the account.
        $storeIds = $this->storeId ? [$this->storeId] : $this->allStoreIds();

        foreach ($storeIds as $storeId) {
            $client = $this->client()->withHeaders(['X-PF-Store-Id' => $storeId]);
            $offset = 0;
            $limit = 100;

            do {
                $list = $client->get(self::BASE.'/store/products', ['limit' => $limit, 'offset' => $offset])
                    ->throw()->json('result', []);

                foreach ($list as $summary) {
                    $detail = $client->get(self::BASE.'/store/products/'.$summary['id'])->throw()->json('result');
                    yield $this->toSyncedProduct($detail);
                }
                $offset += $limit;
            } while (count($list) === $limit);
        }
    }

    /** @return list<int|string> */
    private function allStoreIds(): array
    {
        return array_column($this->client()->get(self::BASE.'/stores')->throw()->json('result', []), 'id');
    }

    /** @param  array<string, mixed>  $detail */
    private function toSyncedProduct(array $detail): SyncedProduct
    {
        $sp = $detail['sync_product'] ?? [];
        $variants = [];
        $images = [];

        foreach ($detail['sync_variants'] ?? [] as $i => $v) {
            $variants[] = new SyncedVariant(
                // The catalogue variant id (for cost/margin via unitCost); the sync-variant
                // id used for ordering is kept as externalId.
                providerUid: (string) ($v['variant_id'] ?? $v['id']),
                title: $v['name'] ?? '',
                options: array_filter(['Size' => $v['size'] ?? null, 'Color' => $v['color'] ?? null], fn ($x) => $x !== null),
                externalId: (string) $v['id'],
                price: isset($v['retail_price']) ? (float) $v['retail_price'] : null,
            );

            $files = $v['files'] ?? null;
            $files = is_array($files) ? $files : [];
            $preview = collect($files)->firstWhere('type', 'preview')['preview_url']
                ?? ($files[0]['preview_url'] ?? null);
            if ($preview) {
                $images[] = new SyncedImage(
                    id: (string) $v['id'],
                    url: $preview,
                    isPrimary: $i === 0,
                    variantExternalIds: [(string) $v['id']],
                );
            }
        }

        return new SyncedProduct(
            externalId: (string) ($sp['id'] ?? ''),
            title: $sp['name'] ?? 'Untitled',
            description: null,
            handle: Str::slug($sp['name'] ?? ('product-'.($sp['id'] ?? ''))),
            variants: $variants,
            images: $images,
        );
    }

    public function sizeGuideForVariant(string $variantUid): ?array
    {
        // Our stored provider_uid is the catalogue variant id; resolve it to the parent
        // catalogue product, whose /sizes endpoint has the measurement chart.
        $productId = $this->client()
            ->get(self::BASE."/products/variant/{$variantUid}")
            ->throw()
            ->json('result.variant.product_id');

        return $productId ? $this->sizeGuide((string) $productId) : null;
    }

    /** @return list<array{id:int, size:?string, color:?string, price:?string}> */
    private function productVariants(string $catalogUid): array
    {
        return $this->client()
            ->get(self::BASE."/products/{$catalogUid}")
            ->throw()
            ->json('result.variants', []);
    }

    /**
     * @param  array<string, mixed>  $sizesResult
     * @return array<string, array<string, string>> [measurement label => [size => value]]
     */
    private function measurementRows(array $sizesResult): array
    {
        $table = $sizesResult['size_tables'][0] ?? null;
        if (! $table) {
            return [];
        }

        $rows = [];
        foreach ($table['measurements'] ?? [] as $m) {
            $label = $m['type_label'] ?? 'Measurement';
            foreach ($m['values'] ?? [] as $val) {
                // Some measurements are a single value, others a min/max range.
                $rows[$label][$val['size']] = $val['value'] ?? ($val['min_value'] ?? '');
            }
        }

        return $rows;
    }

    private function fmt(?string $value): string
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return (string) ($value ?? '');
        }

        return rtrim(rtrim(number_format(round((float) $value, 1), 1, '.', ''), '0'), '.');
    }

    private function client(): PendingRequest
    {
        $client = Http::withToken($this->apiKey)->acceptJson()->asJson();

        return $this->storeId ? $client->withHeaders(['X-PF-Store-Id' => $this->storeId]) : $client;
    }

    /** @return array<string, mixed> */
    private function recipient(Address $a): array
    {
        return array_filter([
            'name' => $a->name,
            'address1' => $a->line1,
            'address2' => $a->line2,
            'city' => $a->city,
            'state_code' => $a->state,
            'country_code' => $a->country,
            'zip' => $a->postalCode,
            'email' => $a->email,
            'phone' => $a->phone,
        ], fn ($v) => $v !== null);
    }

    /** @param  array<string, mixed>  $data */
    private function toResult(array $data): FulfillmentResult
    {
        $shipment = $data['shipments'][0] ?? [];

        return new FulfillmentResult(
            id: (string) ($data['id'] ?? ''),
            status: $this->mapStatus($data['status'] ?? null),
            trackingNumber: $shipment['tracking_number'] ?? null,
            trackingUrl: $shipment['tracking_url'] ?? null,
            raw: $data,
        );
    }

    private function mapStatus(?string $status): FulfillmentStatus
    {
        return match ($status) {
            'draft' => FulfillmentStatus::Draft,
            'pending', 'onhold' => FulfillmentStatus::Pending,
            'inprocess', 'partial' => FulfillmentStatus::InProduction,
            'shipped' => FulfillmentStatus::Shipped,
            'fulfilled' => FulfillmentStatus::Delivered,
            'canceled', 'cancelled' => FulfillmentStatus::Cancelled,
            'failed' => FulfillmentStatus::Failed,
            default => FulfillmentStatus::Unknown,
        };
    }
}
