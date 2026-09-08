<?php

namespace App\Services;

use App\Models\ArchiveImport;
use App\Models\Chain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

class ArchiveImportService
{
    private const CSV_CHUNK = 3000;

    /**
     * Fetch the list of available archives from the cijene.dev API,
     * newest first.
     */
    public function listAvailableArchives(): array
    {
        $baseUrl = config('cijene.base_url');

        $response = Http::timeout(30)->get("{$baseUrl}/v0/list");
        $response->throw();

        $archives = $response->json('archives', []);

        usort($archives, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return $archives;
    }

    public function latestArchive(): ?array
    {
        return $this->listAvailableArchives()[0] ?? null;
    }

    /**
     * Download and import the given archive. Creates/updates the matching
     * archive_imports row so progress is visible from the UI.
     */
    public function import(string $date, string $sourceUrl, ?int $sizeBytes, string $triggeredBy = 'manual', bool $force = false): ArchiveImport
    {
        set_time_limit(0);
        ini_set('memory_limit', '1024M');

        $import = ArchiveImport::updateOrCreate(
            ['archive_date' => $date],
            [
                'source_url' => $sourceUrl,
                'size_bytes' => $sizeBytes,
                'triggered_by' => $triggeredBy,
            ],
        );

        if ($import->status === 'completed' && ! $force) {
            return $import;
        }

        $import->update([
            'status' => 'downloading',
            'progress' => ['phase' => 'downloading', 'percent' => 0, 'phase_started_at' => now()->toIso8601String()],
            'started_at' => now(),
            'finished_at' => null,
            'error_message' => null,
        ]);

        $workDir = storage_path("app/archives/{$date}");
        $zipPath = "{$workDir}.zip";
        $extractDir = "{$workDir}-extracted";

        try {
            $this->downloadZip($sourceUrl, $zipPath, $import);

            $import->update(['status' => 'importing']);
            $this->setProgress($import, ['phase' => 'extracting', 'phase_started_at' => now()->toIso8601String()]);

            $rootDir = $this->extractZip($zipPath, $extractDir);
            $stats = $this->processArchive($rootDir, $date, $import);

            $import->update([
                'status' => 'completed',
                'progress' => null,
                'finished_at' => now(),
                'stats' => $stats,
            ]);
        } catch (Throwable $e) {
            Log::error('Archive import failed', ['date' => $date, 'error' => $e->getMessage()]);

            $import->update([
                'status' => 'failed',
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        } finally {
            @unlink($zipPath);
            $this->deleteDirectory($extractDir);
        }

        return $import->fresh();
    }

    /**
     * Merge fields into the import's live-progress column so the dashboard
     * (polling every few seconds) can show what's happening right now.
     * Uses a plain DB update rather than touching $import's own in-memory
     * attributes, so callers don't have to keep re-fetching the model.
     */
    private function setProgress(ArchiveImport $import, array $patch): void
    {
        $current = DB::table('archive_imports')->where('id', $import->id)->value('progress');
        $current = $current ? json_decode($current, true) : [];

        DB::table('archive_imports')
            ->where('id', $import->id)
            ->update(['progress' => json_encode(array_merge($current, $patch))]);
    }

    private function downloadZip(string $url, string $destPath, ArchiveImport $import): void
    {
        if (! is_dir(dirname($destPath))) {
            mkdir(dirname($destPath), 0755, true);
        }

        $lastReported = 0.0;

        $response = Http::timeout(600)->withOptions([
            'sink' => $destPath,
            'progress' => function (int $downloadTotal, int $downloaded) use ($import, &$lastReported) {
                if ($downloadTotal <= 0) {
                    return;
                }

                $now = microtime(true);

                if ($downloaded < $downloadTotal && $now - $lastReported < 1.0) {
                    return;
                }

                $lastReported = $now;

                $this->setProgress($import, [
                    'phase' => 'downloading',
                    'percent' => (int) round(($downloaded / $downloadTotal) * 100),
                    'downloaded_bytes' => $downloaded,
                    'total_bytes' => $downloadTotal,
                ]);
            },
        ])->get($url);

        $response->throw();
    }

    private function extractZip(string $zipPath, string $extractDir): string
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Could not open archive zip at {$zipPath}");
        }

        if (! is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        $zip->extractTo($extractDir);
        $zip->close();

        return $this->findChainsRoot($extractDir, 2);
    }

    /**
     * The zip's chain folders may sit at the extraction root, or one level
     * under a wrapper folder (as in manually re-zipped samples). Walk down
     * until we find a directory whose children look like chain folders.
     */
    private function findChainsRoot(string $dir, int $maxDepth): string
    {
        if ($this->looksLikeChainsRoot($dir)) {
            return $dir;
        }

        if ($maxDepth <= 0) {
            throw new RuntimeException("Could not locate chain folders under {$dir}");
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '__MACOSX') {
                continue;
            }

            $path = "{$dir}/{$entry}";

            if (is_dir($path)) {
                try {
                    return $this->findChainsRoot($path, $maxDepth - 1);
                } catch (RuntimeException) {
                    continue;
                }
            }
        }

        throw new RuntimeException("Could not locate chain folders under {$dir}");
    }

    private function looksLikeChainsRoot(string $dir): bool
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '__MACOSX') {
                continue;
            }

            $path = "{$dir}/{$entry}";

            if (is_dir($path) && is_file("{$path}/prices.csv")) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{chains: array, history_inserted: int, prices_upserted: int, stale_removed: int}
     */
    private function processArchive(string $rootDir, string $archiveDate, ArchiveImport $import): array
    {
        DB::table('price_import_staging')->truncate();

        $chainDirs = [];
        foreach (scandir($rootDir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || $entry === '__MACOSX' || str_starts_with($entry, '.')) {
                continue;
            }

            $chainDir = "{$rootDir}/{$entry}";

            if (is_dir($chainDir) && is_file("{$chainDir}/prices.csv")) {
                $chainDirs[$entry] = $chainDir;
            }
        }

        $chainNames = config('cijene.chain_names', []);
        $chainsTotal = count($chainDirs);

        $this->setProgress($import, [
            'phase' => 'importing',
            'phase_started_at' => now()->toIso8601String(),
            'chains_total' => $chainsTotal,
            'chains_done' => 0,
        ]);

        $chainStats = [];
        $successfulChainIds = [];
        $chainsDone = 0;

        foreach ($chainDirs as $entry => $chainDir) {
            $this->setProgress($import, [
                'current_chain' => $entry,
                'current_chain_name' => $chainNames[$entry] ?? ucwords(str_replace(['-', '_'], ' ', $entry)),
                'chains_done' => $chainsDone,
            ]);

            try {
                $chainStats[$entry] = $this->importChainDirectory($entry, $chainDir);
                $successfulChainIds[] = $chainStats[$entry]['chain_id'];
            } catch (Throwable $e) {
                Log::error("Failed importing chain '{$entry}'", ['error' => $e->getMessage()]);
                $chainStats[$entry] = ['error' => $e->getMessage()];
            }

            $chainsDone++;
            $this->setProgress($import, ['chains_done' => $chainsDone]);
        }

        $this->setProgress($import, [
            'phase' => 'finalizing',
            'phase_started_at' => now()->toIso8601String(),
            'current_chain' => null,
        ]);

        $this->deduplicateStaging();

        $historyInserted = $this->insertPriceHistoryFromStaging($archiveDate);
        $pricesUpserted = $this->upsertPricesFromStaging($archiveDate);
        $staleRemoved = $this->removeStalePrices($successfulChainIds, $archiveDate);

        DB::table('price_import_staging')->truncate();

        return [
            'chains' => $chainStats,
            'history_inserted' => $historyInserted,
            'prices_upserted' => $pricesUpserted,
            'stale_removed' => $staleRemoved,
        ];
    }

    /**
     * @return array{chain_id: int, stores: int, products: int, prices_staged: int, prices_skipped: int}
     */
    private function importChainDirectory(string $code, string $chainDir): array
    {
        $chainNames = config('cijene.chain_names', []);
        $chain = Chain::firstOrCreate(
            ['code' => $code],
            ['name' => $chainNames[$code] ?? ucwords(str_replace(['-', '_'], ' ', $code))],
        );

        $storeMap = $this->upsertStores($chain->id, "{$chainDir}/stores.csv");
        $productMap = $this->upsertProducts($chain->id, "{$chainDir}/products.csv");
        [$staged, $skipped] = $this->stagePrices("{$chainDir}/prices.csv", $productMap, $storeMap);

        return [
            'chain_id' => $chain->id,
            'stores' => count($storeMap),
            'products' => count($productMap),
            'prices_staged' => $staged,
            'prices_skipped' => $skipped,
        ];
    }

    /**
     * @return array<string, int> store_code => internal id
     */
    private function upsertStores(int $chainId, string $csvPath): array
    {
        if (! is_file($csvPath)) {
            return [];
        }

        $rows = [];
        foreach ($this->readCsv($csvPath) as $row) {
            $rows[] = [
                'chain_id' => $chainId,
                'store_code' => $row['store_id'] ?? '',
                'type' => $this->nullIfEmpty($row['type'] ?? null),
                'address' => $this->nullIfEmpty($row['address'] ?? null),
                'city' => $this->nullIfEmpty($row['city'] ?? null),
                'zipcode' => $this->nullIfEmpty($row['zipcode'] ?? null),
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($rows, self::CSV_CHUNK) as $chunk) {
            DB::table('stores')->upsert(
                $chunk,
                ['chain_id', 'store_code'],
                ['type', 'address', 'city', 'zipcode', 'updated_at'],
            );
        }

        return DB::table('stores')
            ->where('chain_id', $chainId)
            ->pluck('id', 'store_code')
            ->toArray();
    }

    /**
     * @return array<string, int> product_code => internal id
     */
    private function upsertProducts(int $chainId, string $csvPath): array
    {
        if (! is_file($csvPath)) {
            return [];
        }

        $rows = [];
        foreach ($this->readCsv($csvPath) as $row) {
            $rows[] = [
                'chain_id' => $chainId,
                'product_code' => $row['product_id'] ?? '',
                'barcode' => $this->nullIfEmpty($row['barcode'] ?? null),
                'name' => $row['name'] ?? '',
                'brand' => $this->nullIfEmpty($row['brand'] ?? null),
                'category' => $this->nullIfEmpty($row['category'] ?? null),
                'unit' => $this->nullIfEmpty($row['unit'] ?? null),
                'quantity' => $this->nullIfEmpty($row['quantity'] ?? null),
                'updated_at' => now(),
                'created_at' => now(),
            ];
        }

        foreach (array_chunk($rows, self::CSV_CHUNK) as $chunk) {
            DB::table('products')->upsert(
                $chunk,
                ['chain_id', 'product_code'],
                ['barcode', 'name', 'brand', 'category', 'unit', 'quantity', 'updated_at'],
            );
        }

        return DB::table('products')
            ->where('chain_id', $chainId)
            ->pluck('id', 'product_code')
            ->toArray();
    }

    /**
     * @param  array<string, int>  $productMap
     * @param  array<string, int>  $storeMap
     * @return array{0: int, 1: int} [staged count, skipped count]
     */
    private function stagePrices(string $csvPath, array $productMap, array $storeMap): array
    {
        if (! is_file($csvPath)) {
            return [0, 0];
        }

        $staged = 0;
        $skipped = 0;
        $buffer = [];

        foreach ($this->readCsv($csvPath) as $row) {
            $productId = $productMap[$row['product_id'] ?? ''] ?? null;
            $storeId = $storeMap[$row['store_id'] ?? ''] ?? null;

            if (! $productId || ! $storeId) {
                $skipped++;

                continue;
            }

            $buffer[] = [
                'product_id' => $productId,
                'store_id' => $storeId,
                'price' => $this->nullIfEmpty($row['price'] ?? null),
                'unit_price' => $this->nullIfEmpty($row['unit_price'] ?? null),
                'best_price_30' => $this->nullIfEmpty($row['best_price_30'] ?? null),
                'anchor_price' => $this->nullIfEmpty($row['anchor_price'] ?? null),
                'special_price' => $this->nullIfEmpty($row['special_price'] ?? null),
            ];
            $staged++;

            if (count($buffer) >= self::CSV_CHUNK) {
                DB::table('price_import_staging')->insert($buffer);
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('price_import_staging')->insert($buffer);
        }

        return [$staged, $skipped];
    }

    /**
     * Source CSVs occasionally repeat the same (product, store) pair. Keep
     * only the last occurrence so the final upsert never touches a row twice.
     */
    private function deduplicateStaging(): void
    {
        DB::statement(<<<'SQL'
            DELETE FROM price_import_staging a
            USING price_import_staging b
            WHERE a.product_id = b.product_id
              AND a.store_id = b.store_id
              AND a.id < b.id
        SQL);
    }

    private function insertPriceHistoryFromStaging(string $archiveDate): int
    {
        return DB::affectingStatement(<<<'SQL'
            INSERT INTO price_history (product_id, store_id, price, special_price, recorded_date, created_at)
            SELECT s.product_id, s.store_id, s.price, s.special_price, ?, now()
            FROM price_import_staging s
            LEFT JOIN prices p ON p.product_id = s.product_id AND p.store_id = s.store_id
            WHERE p.id IS NULL
               OR p.price IS DISTINCT FROM s.price
               OR p.special_price IS DISTINCT FROM s.special_price
        SQL, [$archiveDate]);
    }

    private function upsertPricesFromStaging(string $archiveDate): int
    {
        return DB::affectingStatement(<<<'SQL'
            INSERT INTO prices (product_id, store_id, price, unit_price, best_price_30, anchor_price, special_price, archive_date, created_at, updated_at)
            SELECT s.product_id, s.store_id, s.price, s.unit_price, s.best_price_30, s.anchor_price, s.special_price, ?, now(), now()
            FROM price_import_staging s
            ON CONFLICT (product_id, store_id) DO UPDATE SET
                price = EXCLUDED.price,
                unit_price = EXCLUDED.unit_price,
                best_price_30 = EXCLUDED.best_price_30,
                anchor_price = EXCLUDED.anchor_price,
                special_price = EXCLUDED.special_price,
                archive_date = EXCLUDED.archive_date,
                updated_at = EXCLUDED.updated_at
        SQL, [$archiveDate]);
    }

    /**
     * Drop prices for products that vanished from a successfully-imported
     * chain's assortment today, so the table always reflects what each
     * chain currently sells.
     */
    private function removeStalePrices(array $chainIds, string $archiveDate): int
    {
        $chainIds = array_values(array_unique(array_map('intval', $chainIds)));

        if ($chainIds === []) {
            return 0;
        }

        $idList = implode(',', $chainIds);

        return DB::delete(<<<SQL
            DELETE FROM prices
            USING products
            WHERE prices.product_id = products.id
              AND products.chain_id IN ({$idList})
              AND prices.archive_date <> ?
        SQL, [$archiveDate]);
    }

    /**
     * @return \Generator<array<string, string>>
     */
    private function readCsv(string $path): \Generator
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("Could not open CSV file {$path}");
        }

        try {
            $header = fgetcsv($handle);

            if ($header === false) {
                return;
            }

            $columns = count($header);

            while (($row = fgetcsv($handle)) !== false) {
                if ($row === [null] || $row === false) {
                    continue;
                }

                $row = array_slice(array_pad($row, $columns, null), 0, $columns);

                yield array_combine($header, $row);
            }
        } finally {
            fclose($handle);
        }
    }

    private function nullIfEmpty(?string $value): ?string
    {
        return ($value === null || $value === '') ? null : $value;
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($dir);
    }
}
