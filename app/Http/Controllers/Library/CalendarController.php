<?php

declare(strict_types=1);

namespace App\Http\Controllers\Library;

use App\Http\Controllers\Controller;
use App\Http\Requests\Library\CalendarRequest;
use App\Services\Library\LibraryCalendar;
use Carbon\CarbonImmutable;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    /** Padding around the month so the grid's leading/trailing days and every timezone are covered. */
    private const int PADDING_DAYS = 7;

    public function __invoke(CalendarRequest $calendarRequest, LibraryCalendar $libraryCalendar): Response
    {
        $validated = $calendarRequest->validated();
        $month = isset($validated['month'])
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $validated['month'], 'UTC')
            : now()->utc()->startOfMonth();

        $start = $month->startOfMonth()->subDays(self::PADDING_DAYS);
        $end = $month->endOfMonth()->addDays(self::PADDING_DAYS);

        return Inertia::render('Library/Calendar', [
            'month' => $month->format('Y-m'),
            'calendar' => Inertia::defer(fn (): array => $libraryCalendar->between($start, $end)),
        ]);
    }
}
