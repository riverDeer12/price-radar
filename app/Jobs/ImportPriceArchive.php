<?php

namespace App\Jobs;

use App\Services\ArchiveImportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportPriceArchive implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(
        public string $date,
        public string $sourceUrl,
        public ?int $sizeBytes,
        public string $triggeredBy = 'manual',
        public bool $force = false,
    ) {}

    public function handle(ArchiveImportService $service): void
    {
        $service->import($this->date, $this->sourceUrl, $this->sizeBytes, $this->triggeredBy, $this->force);
    }
}
