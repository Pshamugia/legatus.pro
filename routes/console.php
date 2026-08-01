<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Full-site knowledge refresh is intentionally manual. Automatically queuing
// every tenant/source at once can exhaust shared-hosting CPU and process limits.
Schedule::command('legatus:purge-expired-data')->dailyAt('03:45')->withoutOverlapping();
Schedule::command('legatus:expire-reservations')->everyMinute()->withoutOverlapping();
// The catalog is an authoritative discovery snapshot. Price and stock are checked
// live per customer question, so an hourly refresh avoids hammering shared stores.
Schedule::command('legatus:sync-commerce')->hourly()->withoutOverlapping();
Schedule::command('legatus:dispatch-channel-outbox')->everyMinute()->withoutOverlapping();
// Webhooks remain the real-time path. This lightweight reconciliation closes
// delivery gaps caused by Meta development mode, routing changes, or outages.
Schedule::command('legatus:reconcile-meta-inbox')->everyMinute()->withoutOverlapping();

// Shared-hosting production does not necessarily provide Supervisor. Run a
// short-lived worker from the platform scheduler so every tenant's one-click
// sync continues after the browser request ends. The scheduler is configured
// once by the Legatus operator; individual businesses never use a terminal.
Schedule::command('queue:work database --queue=channels,default --stop-when-empty --max-time=50 --timeout=3600 --tries=3 --memory=128')
    ->everyMinute()
    // Shared hosts commonly disable the shell primitives Laravel uses for
    // runInBackground(). Cron already provides the outer process, so execute
    // the bounded worker directly and use the scheduler lock for overlap.
    ->withoutOverlapping(10);
Schedule::command('queue:work database --queue=knowledge --stop-when-empty --max-jobs=1 --max-time=45 --timeout=45 --tries=1 --memory=128')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);
