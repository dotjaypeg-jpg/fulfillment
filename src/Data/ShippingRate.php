<?php

namespace Chargefield\Fulfillment\Data;

/**
 * A shipping option returned by a provider's quote endpoint. `id` is the
 * provider's method identifier, passed back into createOrder() to select it.
 */
final readonly class ShippingRate
{
    public function __construct(
        public string $id,
        public string $name,
        public float $amount,
        public string $currency,
        public ?int $minDeliveryDays = null,
        public ?int $maxDeliveryDays = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'minDeliveryDays' => $this->minDeliveryDays,
            'maxDeliveryDays' => $this->maxDeliveryDays,
        ];
    }
}
