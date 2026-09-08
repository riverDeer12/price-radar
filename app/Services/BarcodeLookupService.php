<?php

namespace App\Services;

use App\Support\Barcode;
use Illuminate\Support\Facades\DB;

class BarcodeLookupService
{
    /**
     * Look up every chain that sells the exact scanned barcode, and the
     * cheapest current price per chain (optionally narrowed to one city).
     * Built for the in-store case: the shelf has no price tag, scan the
     * product and see what it should cost — here or elsewhere.
     *
     * @return array{found: bool, name: ?string, offers: array}
     */
    public function lookup(string $barcode, ?string $city = null): array
    {
        // A handful of chains reuse one placeholder code (e.g.
        // "9999999999994") for dozens of unrelated items that lack a real
        // barcode (loose produce, plants). Reject those up front instead of
        // returning a nonsense mix of products as if they were the same item.
        if (! Barcode::isRealEan($barcode)) {
            return ['found' => false, 'name' => null, 'offers' => []];
        }

        $products = DB::table('products')
            ->where('barcode', $barcode)
            ->get(['id', 'name']);

        if ($products->isEmpty()) {
            return ['found' => false, 'name' => null, 'offers' => []];
        }

        $productIds = $products->pluck('id')->all();

        $query = DB::table('prices')
            ->join('stores', 'stores.id', '=', 'prices.store_id')
            ->join('chains', 'chains.id', '=', 'stores.chain_id')
            ->whereIn('prices.product_id', $productIds)
            ->whereNotNull('prices.price');

        if ($city) {
            $query->where('stores.city', $city);
        }

        $rows = $query->get([
            'chains.id as chain_id', 'chains.name as chain_name', 'prices.price',
            'stores.address as store_address', 'stores.city as store_city',
        ]);

        $offers = $rows
            ->groupBy('chain_id')
            ->map(function ($group) {
                $cheapest = $group->sortBy('price')->first();

                return [
                    'chain_name' => $cheapest->chain_name,
                    'price' => (float) $cheapest->price,
                    'store_count' => $group->count(),
                    'sample_address' => $cheapest->store_address,
                    'sample_city' => $cheapest->store_city,
                ];
            })
            ->sortBy('price')
            ->values()
            ->all();

        // Shortest name tends to be the plain, undecorated version of the
        // product — a reasonable label to show back to the user.
        $displayName = $products->sortBy(fn ($p) => mb_strlen($p->name))->first()->name;

        return ['found' => true, 'name' => $displayName, 'offers' => $offers];
    }
}
