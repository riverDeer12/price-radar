<?php

namespace App\Support;

class Barcode
{
    /**
     * Whether a scraped barcode value looks like a genuine, product-specific
     * EAN/UPC rather than the archive's chain-scoped fallback code
     * ("<chain>:<product_id>") or a placeholder some chains use for items
     * without a real barcode (loose produce, plants — seen in the data as
     * the same digit-string, e.g. "9999999999994", shared by dozens of
     * completely unrelated products within one chain).
     *
     * A real EAN/UPC always has meaningful digit variety (manufacturer +
     * product code + check digit), so requiring at least 3 distinct digits
     * is enough to reject known placeholder patterns without risking false
     * negatives on genuine barcodes.
     */
    public static function isRealEan(?string $barcode): bool
    {
        if (! $barcode || ! preg_match('/^\d{8,14}$/', $barcode)) {
            return false;
        }

        return count(array_unique(str_split($barcode))) >= 3;
    }
}
