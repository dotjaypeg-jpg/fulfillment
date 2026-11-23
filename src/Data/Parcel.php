<?php

namespace Chargefield\Fulfillment\Data;

/**
 * A physical parcel to be labelled: total weight, plus optional dimensions some
 * carriers require to rate/label (Canada Post accepts a label without them).
 * Weight is grams, dimensions centimetres.
 */
final readonly class Parcel
{
    public function __construct(
        public int $weightGrams,
        public ?float $lengthCm = null,
        public ?float $widthCm = null,
        public ?float $heightCm = null,
    ) {}

    /** Weight in kilograms, clamped to a sane minimum (1 g). */
    public function weightKg(): float
    {
        return max(1, $this->weightGrams) / 1000;
    }

    public function hasDimensions(): bool
    {
        return $this->lengthCm !== null && $this->widthCm !== null && $this->heightCm !== null;
    }
}
