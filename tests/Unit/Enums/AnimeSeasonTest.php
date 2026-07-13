<?php

declare(strict_types=1);

use App\Enums\AnimeSeason;

test('forMonth maps every month to the correct season', function (int $month, AnimeSeason $animeSeason): void {
    expect(AnimeSeason::forMonth($month))->toBe($animeSeason);
})->with([
    'jan' => [1, AnimeSeason::Winter],
    'feb' => [2, AnimeSeason::Winter],
    'mar' => [3, AnimeSeason::Winter],
    'apr' => [4, AnimeSeason::Spring],
    'may' => [5, AnimeSeason::Spring],
    'jun' => [6, AnimeSeason::Spring],
    'jul' => [7, AnimeSeason::Summer],
    'aug' => [8, AnimeSeason::Summer],
    'sep' => [9, AnimeSeason::Summer],
    'oct' => [10, AnimeSeason::Fall],
    'nov' => [11, AnimeSeason::Fall],
    'dec' => [12, AnimeSeason::Fall],
]);

test('startMonth returns the first calendar month of the season', function (AnimeSeason $animeSeason, int $expected): void {
    expect($animeSeason->startMonth())->toBe($expected);
})->with([
    [AnimeSeason::Winter, 1],
    [AnimeSeason::Spring, 4],
    [AnimeSeason::Summer, 7],
    [AnimeSeason::Fall, 10],
]);

test('anilist returns the uppercase MediaSeason value', function (): void {
    expect(AnimeSeason::Winter->anilist())->toBe('WINTER');
    expect(AnimeSeason::Spring->anilist())->toBe('SPRING');
    expect(AnimeSeason::Summer->anilist())->toBe('SUMMER');
    expect(AnimeSeason::Fall->anilist())->toBe('FALL');
});

test('next advances the season and wraps the year at fall', function (): void {
    expect(AnimeSeason::Winter->next(2024))->toBe(['season' => AnimeSeason::Spring, 'year' => 2024]);
    expect(AnimeSeason::Spring->next(2024))->toBe(['season' => AnimeSeason::Summer, 'year' => 2024]);
    expect(AnimeSeason::Summer->next(2024))->toBe(['season' => AnimeSeason::Fall, 'year' => 2024]);

    // Fall wraps into next year's Winter.
    expect(AnimeSeason::Fall->next(2024))->toBe(['season' => AnimeSeason::Winter, 'year' => 2025]);
});

test('previous rewinds the season and wraps the year at winter', function (): void {
    // Winter wraps back into previous year's Fall.
    expect(AnimeSeason::Winter->previous(2024))->toBe(['season' => AnimeSeason::Fall, 'year' => 2023]);

    expect(AnimeSeason::Spring->previous(2024))->toBe(['season' => AnimeSeason::Winter, 'year' => 2024]);
    expect(AnimeSeason::Summer->previous(2024))->toBe(['season' => AnimeSeason::Spring, 'year' => 2024]);
    expect(AnimeSeason::Fall->previous(2024))->toBe(['season' => AnimeSeason::Summer, 'year' => 2024]);
});

test('label is a capitalised human readable string', function (): void {
    expect(AnimeSeason::Winter->label())->toBe('Winter');
    expect(AnimeSeason::Fall->label())->toBe('Fall');
});
