<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sgar:activities:queue-reminders')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('sgar:backup:create')
    ->dailyAt((string) config('backup.schedule_time', '02:00'))
    ->withoutOverlapping();
