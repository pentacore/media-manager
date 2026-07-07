<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\TimeWindow;
use App\Services\Statistics\StatisticsRepository;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StatisticsController extends Controller
{
    public function __invoke(Request $request, StatisticsRepository $statisticsRepository): Response
    {
        $timeWindow = TimeWindow::fromRequest($request->string('window')->toString(), TimeWindow::Last30d);

        $plays = $statisticsRepository->total('watch.plays', $timeWindow);
        $finishes = $statisticsRepository->total('watch.finishes', $timeWindow);
        $seconds = $statisticsRepository->total('watch.seconds', $timeWindow);
        $downloads = $statisticsRepository->total('downloads.completed', $timeWindow);

        return Inertia::render('Statistics/Index', [
            'window' => $timeWindow->value,
            'windows' => TimeWindow::options(),
            'headline' => [
                'plays' => $plays['count'],
                'finishes' => $finishes['count'],
                'watchHours' => round(($seconds['sum'] ?? 0) / 3600, 1),
                'downloads' => $downloads['count'],
            ],
            'watchSeries' => $statisticsRepository->series('watch.plays', $timeWindow),
            'downloadSeries' => $statisticsRepository->series('downloads.completed', $timeWindow),
            'librarySeries' => $statisticsRepository->series('library.movies', $timeWindow),
            'requestFunnel' => [
                'created' => $statisticsRepository->total('requests.created', $timeWindow)['count'],
                'approved' => $statisticsRepository->total('requests.approved', $timeWindow)['count'],
                'declined' => $statisticsRepository->total('requests.declined', $timeWindow)['count'],
                'available' => $statisticsRepository->total('requests.available', $timeWindow)['count'],
            ],
            'leaderboard' => Inertia::defer(fn (): array => $statisticsRepository->watchLeaderboard($timeWindow)),
            'topTitles' => Inertia::defer(fn (): array => $statisticsRepository->topTitles($timeWindow)),
            'hourHeatmap' => Inertia::defer(fn (): array => $statisticsRepository->watchHeatmap($timeWindow)),
        ]);
    }
}
