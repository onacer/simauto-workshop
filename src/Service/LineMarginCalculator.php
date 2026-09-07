<?php

namespace App\Service;

final class LineMarginCalculator
{
    /** Management margins use TTC exclusively; legal invoice tax fields are ignored. */
    public static function decorate(array $line, float $vatRate): array
    {
        $quantity = (float) ($line['quantity'] ?? 0);
        $enteredTotal = round((float) ($line['total'] ?? 0), 2);
        $productId = (int) ($line['product_id'] ?? 0);
        $productType = (string) ($line['product_type'] ?? '');
        $missingProduct = $productId <= 0 || $productType === '';
        $stockable = !$missingProduct
            && (string) ($line['line_type'] ?? '') === 'product'
            && $productType === 'stockable';
        $costTtc = $stockable ? round((float) ($line['purchase_price'] ?? 0) * $quantity, 2) : 0.0;
        $margin = round($enteredTotal - $costTtc, 2);
        return array_merge($line, [
            'product_type' => $productType ?: null,
            'quantity' => $quantity,
            'unit_price' => (float) ($line['unit_price'] ?? 0),
            'total' => $enteredTotal,
            'cost_ttc' => $costTtc,
            'margin' => $margin,
            'margin_rate' => $enteredTotal > 0 ? round(($margin / $enteredTotal) * 100, 2) : 0.0,
            'is_estimated' => $missingProduct,
        ]);
    }
}
