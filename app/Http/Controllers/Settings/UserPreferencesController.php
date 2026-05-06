<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateUserPreferencesRequest;
use App\Support\UserPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class UserPreferencesController extends Controller
{
    public function edit(): Response
    {
        $user = Auth::user();

        return Inertia::render('settings/Preferences', [
            'preferences' => $user->resolvedPreferences(),
            'timezones' => $this->timezoneOptions(),
            'options' => [
                'time_formats' => UserPreferences::TIME_FORMATS,
                'date_formats' => UserPreferences::DATE_FORMATS,
                'week_starts' => UserPreferences::WEEK_STARTS,
            ],
        ]);
    }

    public function update(UpdateUserPreferencesRequest $updateUserPreferencesRequest): RedirectResponse
    {
        $user = $updateUserPreferencesRequest->user();

        $user->preferences = UserPreferences::withDefaults($updateUserPreferencesRequest->validated());
        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Preferences saved.')]);

        return back();
    }

    /**
     * Group IANA timezones by region prefix so the picker is browseable.
     *
     * @return array<int, array{group: string, zones: array<int, string>}>
     */
    private function timezoneOptions(): array
    {
        $grouped = [];
        foreach (UserPreferences::availableTimezones() as $tz) {
            $group = str_contains($tz, '/') ? explode('/', $tz)[0] : 'Other';
            $grouped[$group][] = $tz;
        }

        ksort($grouped);

        return array_map(
            static fn (string $group, array $zones): array => ['group' => $group, 'zones' => $zones],
            array_keys($grouped),
            $grouped,
        );
    }
}
