<?php

namespace App\Console\Commands;

use App\Jobs\ImportPriceArchive;
use App\Services\ArchiveImportService;
use Illuminate\Console\Command;

class ImportArchive extends Command
{
    protected $signature = 'archive:import
        {date? : Specific archive date (YYYY-MM-DD), defaults to the latest available}
        {--force : Re-import even if this date was already completed}
        {--sync : Run immediately instead of queuing a job}';

    protected $description = 'Download and import the daily cijene.dev price archive';

    public function handle(ArchiveImportService $service): int
    {
        $date = $this->argument('date');

        if ($date) {
            $archive = collect($service->listAvailableArchives())->firstWhere('date', $date);

            if (! $archive) {
                $this->error("Archive for {$date} was not found on cijene.dev.");

                return self::FAILURE;
            }
        } else {
            $archive = $service->latestArchive();

            if (! $archive) {
                $this->error('No archives are currently listed on cijene.dev.');

                return self::FAILURE;
            }
        }

        $this->info("Archive: {$archive['date']} ({$archive['url']})");

        $job = new ImportPriceArchive(
            date: $archive['date'],
            sourceUrl: $archive['url'],
            sizeBytes: $archive['size'] ?? null,
            triggeredBy: 'schedule',
            force: $this->option('force'),
        );

        if ($this->option('sync')) {
            $job->handle($service);
            $this->info('Import finished.');
        } else {
            dispatch($job);
            $this->info('Import job queued.');
        }

        return self::SUCCESS;
    }
}
