<?php

namespace App\Service;

use InvalidArgumentException;

final class PricingCalculator
{
    /** Single extension point for divisor-based selling-price calculation. */
    public static function priceFromMargin(float $basePrice, float $marginPercent): float
    {
        if ($basePrice < 0 || $marginPercent < 0 || $marginPercent >= 100) {
            throw new InvalidArgumentException('Prix ou marge invalide');
        }

        return round($basePrice / ((100 - $marginPercent) / 100), 2);
    }

    public static function supportedMargin(?string $mode): ?float
    {
        $margin = is_numeric($mode) ? (float) $mode : -1;
        return in_array($margin, [35.0, 45.0, 55.0], true) ? $margin : null;
    }
}
