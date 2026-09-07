<?php

namespace App\Service;

final class LineMarginCalculator
{
    /**
     * Unique extension point for the management-margin definition.
     * Stockable products use the existing HT/reference-cost definition.
     * Services and free lines are the strict exception: margin is 100% of the entered
     * line total (quantity and discount included), without extracting VAT.
     */
    public static function decorate(array $line, float $vatRate): array
    {
        $quantity = (float) ($line['quantity'] ?? 0);
        $totalHt = round((float) ($line['total_ht'] ?? $line['total'] ?? 0), 2);
        $enteredTotal = round((float) ($line['total'] ?? $totalHt), 2);
        $productId = (int) ($line['product_id'] ?? 0);
        $productType = (string) ($line['product_type'] ?? '');
        $missingProduct = $productId <= 0 || $productType === '';
        $stockable = !$missingProduct
            && (string) ($line['line_type'] ?? '') === 'product'
            && $productType === 'stockable';
        $service = (string) ($line['line_type'] ?? '') === 'service'
            || $productType === 'service'
            || $missingProduct;

        $costHt = 0.0;
        if ($stockable) {
            $factor = 1 + (max(0, $vatRate) / 100);
            $unitCostHt = (float) ($line['purchase_price'] ?? 0) / $factor;
            $costHt = round($unitCostHt * $quantity, 2);
        }

        $marginBase = $service ? $enteredTotal : $totalHt;
        $margin = $service ? $enteredTotal : round($totalHt - $costHt, 2);
        return array_merge($line, [
            'product_type' => $productType ?: null,
            'quantity' => $quantity,
            'unit_price' => (float) ($line['unit_price'] ?? 0),
            'total_ht' => $totalHt,
            'cost_ht' => $costHt,
            'margin' => $margin,
            'margin_rate' => $marginBase > 0 ? round(($margin / $marginBase) * 100, 2) : 0.0,
            'is_estimated' => $missingProduct,
        ]);
    }
}
