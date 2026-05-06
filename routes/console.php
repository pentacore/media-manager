<?php

declare(strict_types=1);

use App\Console\Commands\Ai\RefreshAiPrices;
use App\Console\Commands\BroadcastDashboardStats;
use App\Console\Commands\CheckServiceHealth;
use App\Console\Commands\CheckServiceVersions;
use App\Console\Commands\PollSabnzbdHistory;
use App\Console\Commands\PruneAiProposedWorkflows;
use App\Console\Commands\RefreshInterventionCount;
use App\Console\Commands\RefreshSabnzbdDownloadCounts;
use Illuminate\Support\Facades\Schedule;

Schedule::command(CheckServiceHealth::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(CheckServiceVersions::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(BroadcastDashboardStats::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(PruneAiProposedWorkflows::class)
    ->daily()
    ->withoutOverlapping();

Schedule::command(RefreshAiPrices::class)
    ->weekly()
    ->withoutOverlapping();

Schedule::command(PollSabnzbdHistory::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(RefreshInterventionCount::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command(RefreshSabnzbdDownloadCounts::class)
    ->everyFiveMinutes()
    ->withoutOverlapping();
