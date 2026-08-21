<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('reel:finalize-sessions')->everyMinute()->withoutOverlapping();
Schedule::command('reel:retain-sessions')->hourly()->withoutOverlapping();
Schedule::command('reel:retry-deletions --apply')->hourly()->withoutOverlapping();
Schedule::command('reel:sweep-orphans')->daily()->withoutOverlapping();
