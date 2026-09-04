<?php

namespace App\Service;

final class LineMarginCalculator
{
    /**
     * Unique extension point for the management-margin definition.
     * Amounts are HT: purchase prices stored TTC are converted using the document VAT rate.
     */
    public static function decorate(array $line, float $vatRate): array
    {
        $quantity = (float) ($line['quantity'] ?? 0);
        $totalHt = round((float) ($line['total_ht'] ?? $line['total'] ?? 0), 2);
        $productId = (int) ($line['product_id'] ?? 0);
        $productType = (string) ($line['product_type'] ?? '');
        $missingProduct = $productId <= 0 || $productType === '';
        $stockable = !$missingProduct
            && (string) ($line['line_type'] ?? '') === 'product'
            && $productType === 'stockable';

        $costHt = 0.0;
        if ($stockable) {
            $factor = 1 + (max(0, $vatRate) / 100);
            $unitCostHt = (float) ($line['purchase_price'] ?? 0) / $factor;
            $costHt = round($unitCostHt * $quantity, 2);
        }

        $margin = round($totalHt - $costHt, 2);
        return array_merge($line, [
            'product_type' => $productType ?: null,
            'quantity' => $quantity,
            'unit_price' => (float) ($line['unit_price'] ?? 0),
            'total_ht' => $totalHt,
            'cost_ht' => $costHt,
            'margin' => $margin,
            'margin_rate' => $totalHt > 0 ? round(($margin / $totalHt) * 100, 2) : 0.0,
            'is_estimated' => $missingProduct,
        ]);
    }
}
