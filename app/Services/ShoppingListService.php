<?php

namespace App\Services;

use App\Support\Barcode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ShoppingListService
{
    private const MAX_STORES_RETURNED = 15;

    /**
     * For each line of the shopping list, find the single best-matching
     * product by name, then compare that exact product's price (matched
     * across chains via barcode, when it's a real EAN) across every store
     * in the given city. Returns per-item results plus stores ranked by
     * how many items they cover and total price.
     *
     * @param  string[]  $items
     * @return array{items: array, stores: array}
     */
    public function search(array $items, string $city): array
    {
        $itemResults = [];
        $priceRows = []; // itemIndex => [storeId => [...]]

        foreach (array_values($items) as $index => $term) {
            $term = trim($term);

            if ($term === '') {
                continue;
            }

            $match = $this->matchCandidates($term);

            if ($match['product_ids'] === []) {
                $itemResults[$index] = [
                    'term' => $term,
                    'matched_name' => null,
                    'status' => 'not_found',
                    'best' => null,
                ];

                continue;
            }

            $offers = $this->offersForCandidates($match['product_ids'], $city);

            if ($offers->isEmpty()) {
                $itemResults[$index] = [
                    'term' => $term,
                    'matched_name' => $match['display_name'],
                    'status' => 'not_in_city',
                    'best' => null,
                ];

                continue;
            }

            $best = $offers->first(); // offers pre-sorted by price asc

            $itemResults[$index] = [
                'term' => $term,
                'matched_name' => $match['display_name'],
                'status' => 'found',
                'best' => $best,
            ];

            foreach ($offers as $offer) {
                $priceRows[$index][$offer->store_id] ??= $offer;
            }
        }

        return [
            'items' => $itemResults,
            'stores' => $this->rankStores($priceRows, count($itemResults)),
        ];
    }

    /**
     * Find the product name that best matches the free-text item, then
     * pull in every product across every chain that's essentially the same
     * naming variant (similarity close to the best match) and resolve each
     * to its full cross-chain candidate set via barcode.
     *
     * Matching on the single best-scoring row alone is fragile: retailers
     * describe the identical item slightly differently ("COCA COLA 2l" vs
     * "GAZ PIĆE COCA COLA 2L" vs "SOK COCA COLA 2L PET..."), and a scraped
     * barcode can occasionally be wrong for one listing. Folding in the
     * near-duplicates before resolving barcodes means one bad/orphan row
     * doesn't collapse an otherwise well-connected, widely-sold product
     * down to a single chain.
     *
     * @return array{display_name: ?string, product_ids: int[]}
     */
    private function matchCandidates(string $term): array
    {
        $like = '%'.$term.'%';
        // Product names conventionally lead with the item itself ("MLIJEKO
        // 2,8% ...", "KRUH SK2"), so a name that *starts* with the search
        // word is almost always what the shopper meant. Without this,
        // "mlijeko" (milk) can out-rank real milk with something like "VRČ
        // ZA MLIJEKO" (a milk jug) purely on trigram similarity, since both
        // are short strings that share the same trigrams.
        $startsWithTerm = [$term, $term.' %'];

        $matches = DB::table('products')
            ->select('id', 'name', 'barcode')
            ->selectRaw('similarity(name, ?) as sim', [$term])
            ->selectRaw('(name ilike ? or name ilike ?) as starts_with_term', $startsWithTerm)
            ->where('name', 'ilike', $like)
            ->orderByDesc('starts_with_term')
            ->orderByDesc('sim')
            ->orderByRaw('length(name) asc')
            ->limit(20)
            ->get();

        if ($matches->isEmpty()) {
            // No literal substring hit — fall back to pure trigram
            // fuzziness, which tolerates typos/plurals/declensions.
            $matches = DB::table('products')
                ->select('id', 'name', 'barcode')
                ->selectRaw('similarity(name, ?) as sim', [$term])
                ->selectRaw('(name ilike ? or name ilike ?) as starts_with_term', $startsWithTerm)
                ->whereRaw('name % ?', [$term])
                ->orderByDesc('starts_with_term')
                ->orderByDesc('sim')
                ->limit(20)
                ->get();
        }

        if ($matches->isEmpty()) {
            return ['display_name' => null, 'product_ids' => []];
        }

        $best = $matches->first();
        $threshold = max(0.0, $best->sim - 0.15);
        $relevant = $matches->filter(fn ($m) => $m->sim >= $threshold);

        $barcodes = $relevant->pluck('barcode')->filter(Barcode::isRealEan(...))->unique()->values();
        $productIds = $relevant->reject(fn ($m) => Barcode::isRealEan($m->barcode))->pluck('id')->all();

        if ($barcodes->isNotEmpty()) {
            $productIds = array_merge(
                $productIds,
                DB::table('products')->whereIn('barcode', $barcodes)->pluck('id')->all(),
            );
        }

        return [
            'display_name' => $best->name,
            'product_ids' => array_values(array_unique($productIds)),
        ];
    }

    /**
     * @param  int[]  $productIds
     */
    private function offersForCandidates(array $productIds, string $city): Collection
    {
        if ($productIds === []) {
            return collect();
        }

        return DB::table('prices')
            ->join('stores', 'stores.id', '=', 'prices.store_id')
            ->join('chains', 'chains.id', '=', 'stores.chain_id')
            ->whereIn('prices.product_id', $productIds)
            ->where('stores.city', $city)
            ->whereNotNull('prices.price')
            ->orderBy('prices.price')
            ->get([
                'prices.store_id', 'prices.price',
                'chains.name as chain_name',
                'stores.address as store_address', 'stores.city as store_city',
            ]);
    }

    /**
     * @param  array<int, array<int, object>>  $priceRows
     */
    private function rankStores(array $priceRows, int $totalItems): array
    {
        $stores = []; // storeId => ['chain_name'=>, 'address'=>, 'city'=>, 'covered'=>, 'total'=>]

        foreach ($priceRows as $itemIndex => $byStore) {
            foreach ($byStore as $storeId => $offer) {
                $stores[$storeId] ??= [
                    'store_id' => $storeId,
                    'chain_name' => $offer->chain_name,
                    'address' => $offer->store_address,
                    'city' => $offer->store_city,
                    'covered' => 0,
                    'total' => 0.0,
                ];

                $stores[$storeId]['covered']++;
                $stores[$storeId]['total'] += (float) $offer->price;
            }
        }

        usort($stores, fn ($a, $b) => $b['covered'] <=> $a['covered'] ?: $a['total'] <=> $b['total']);

        foreach ($stores as &$store) {
            $store['total_items'] = $totalItems;
        }

        return array_slice($stores, 0, self::MAX_STORES_RETURNED);
    }
}
