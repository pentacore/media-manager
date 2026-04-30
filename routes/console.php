<?php

declare(strict_types=1);

use App\Console\Commands\BroadcastDashboardStats;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('services:check-health')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('services:check-versions')
    ->daily()
    ->withoutOverlapping();

Schedule::command(BroadcastDashboardStats::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('ai:prune-proposed-workflows')
    ->daily()
    ->withoutOverlapping();

Schedule::command('ai:refresh-prices')
    ->weekly()
    ->withoutOverlapping();

Schedule::command('sabnzbd:poll-history')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('library:refresh-intervention-count')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('sabnzbd:refresh-download-counts')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground();
