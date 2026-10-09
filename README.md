# chargefield/fulfillment

Vendor-agnostic print-on-demand fulfillment layer shared across Chargefield Inc.
storefronts (johngodfrey.com, chargefield.com). Pure code: contracts, provider
adapters, neutral DTOs, and a driver manager. It never touches Eloquent models or
site-specific config, so each app maps its own orders to the neutral `LineItem`
DTO and supplies its own credentials and pricing.

## What's in here

- `Chargefield\Fulfillment\Contracts\*` - the capability interfaces. `PrintProvider`
  is the base (quote shipping, create order); a provider opts into more by also
  implementing `ProductCatalog`, `ProductSpec`, `StoreSync`, `StockAware`, or
  `ShippingCarrier`. Callers feature-detect with `instanceof`.
- `Chargefield\Fulfillment\Providers\*` - `GelatoProvider`, `PrintfulProvider`,
  `ManualProvider` (self-ship via Canada Post).
- `Chargefield\Fulfillment\Data\*` - neutral DTOs (`LineItem`, `Address`,
  `ShippingRate`, `FulfillmentResult`, catalog + sync DTOs).
- `Chargefield\Fulfillment\FulfillmentManager` - resolves the active provider from
  `config('services.fulfillment.default')`.
- `FulfillmentServiceProvider` - auto-registered; binds the manager and a default
  `PrintProvider`.

## Config the consuming app must provide

The package reads these keys from the host app's `config/services.php`:

```php
'fulfillment' => ['default' => env('FULFILLMENT_PROVIDER', 'gelato')],
'gelato'      => ['key' => env('GELATO_API_KEY')],
'printful'    => ['key' => env('PRINTFUL_API_KEY'), 'store_id' => env('PRINTFUL_STORE_ID')],
// Canada Post keys if the manual provider is used.
```

Per-site product data (frame UIDs, retail prices, garment print areas) stays in each
app's own `config/frames.php` / `config/garments.php` - the package has no opinion on
pricing or which blanks a site sells.

## Local development

Both apps consume this via a Composer path repository during development and a private
VCS repository on Laravel Cloud. After editing the package locally, run
`composer update chargefield/fulfillment` in the consuming app to resync.
