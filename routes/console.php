<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Chains have until 08:00 CET to publish their prices; cijene.dev usually
// has the day's archive ready shortly after. Running at 10:00 leaves margin.
Schedule::command('archive:import')
    ->dailyAt('10:00')
    ->withoutOverlapping()
    ->onOneServer();
