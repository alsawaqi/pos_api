<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sync:sweep-stranded-events')
    ->everyMinute()
    ->name('sweep-stranded-sync-events')
    ->withoutOverlapping(30)
    ->onOneServer()
    ->when(static fn (): bool => config('sync.stranded_sweep_enabled') === true);

Schedule::command('qr:prune-sessions')
    ->hourly()
    ->name('prune-qr-sessions')
    ->withoutOverlapping(60)
    ->onOneServer();
