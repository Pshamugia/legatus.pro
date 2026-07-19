<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('legatus:sync-knowledge')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('legatus:purge-expired-data')->dailyAt('03:45')->withoutOverlapping();
Schedule::command('legatus:expire-reservations')->everyMinute()->withoutOverlapping();
