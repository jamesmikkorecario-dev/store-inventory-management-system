<?php

use App\Services\NotificationService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('notifications:forecasts {--days= : Forecast period in days}', function (NotificationService $service) {
    $days = $this->option('days') !== null ? (int) $this->option('days') : null;
    $result = $service->dispatchForecastStockouts($days);
    $this->info("Scanned {$result['scanned']} candidates, dispatched {$result['notified']} notifications.");
})->purpose('Dispatch stockout forecast notifications for products at risk');

Schedule::command('notifications:forecasts')->daily();
