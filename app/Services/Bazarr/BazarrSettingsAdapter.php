<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Models\ServiceConnection;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use UnexpectedValueException;

final class BazarrSettingsAdapter
{
    /**
     * @var array<string, string>
     */
    private const array WRITE_MAP = [
        'scheduler_enabled' => 'settings-scheduler-enabled',
        'scheduler_interval_hours' => 'settings-scheduler-interval',
        'automatic_subtitle_synchronization' => 'settings-general-auto_sync',
        'use_postprocessing' => 'settings-general-use_postprocessing',
    ];

    /**
     * @return array<string, mixed>
     */
    public function read(ServiceConnection $serviceConnection): array
    {
        $bazarrClient = new BazarrClient($serviceConnection);
        $settings = $bazarrClient->getSettings();

        return [
            'language_profiles' => $this->languageProfiles($bazarrClient->getLanguageProfiles()),
            'profile_assignments' => $this->profileAssignments($settings['profile_assignments'] ?? []),
            'tasks' => $this->tasks($bazarrClient->getTasks()['data']),
            'scheduler' => [
                'enabled' => ($settings['scheduler']['enabled'] ?? false) === true,
                'interval_hours' => $this->boundedInteger($settings['scheduler']['interval_hours'] ?? 24, 1, 168, 24),
            ],
            'subtitle_tools' => [
                'automatic_subtitle_synchronization' => ($settings['subtitle_tools']['automatic_subtitle_synchronization'] ?? false) === true,
                'use_postprocessing' => ($settings['subtitle_tools']['use_postprocessing'] ?? false) === true,
            ],
            'provider_status' => $this->providers($bazarrClient->getProviders()['data']),
            'notifications' => $this->notifications($bazarrClient->getNotifications()),
        ];
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public function update(ServiceConnection $serviceConnection, array $settings): array
    {
        $bazarrClient = new BazarrClient($serviceConnection);

        throw_unless(
            $bazarrClient->getCapabilities()['settings_adapter'] ?? false,
            DomainException::class,
            'This Bazarr version does not support non-secret settings updates.',
        );

        $unknownKeys = array_diff(array_keys($settings), array_keys(self::WRITE_MAP));
        throw_if($unknownKeys !== [], InvalidArgumentException::class, 'Unsupported Bazarr setting: '.implode(', ', $unknownKeys));

        $normalized = [];

        foreach ($settings as $key => $value) {
            $normalized[self::WRITE_MAP[$key]] = $this->validateWriteValue($key, $value);
        }

        $bazarrClient->updateSettings($normalized);
        $changedKeys = array_keys($settings);
        sort($changedKeys);

        return $changedKeys;
    }

    /**
     * @return list<string>
     */
    public static function writableKeys(): array
    {
        return array_keys(self::WRITE_MAP);
    }

    /**
     * A null result means the installed Bazarr version cannot expose the
     * threshold reliably; callers must treat that as a capability limitation
     * rather than an empty probe or a hard failure.
     */
    public function effectiveMinimumScore(ServiceConnection $serviceConnection, string $mediaType): ?int
    {
        try {
            return (new BazarrClient($serviceConnection))->effectiveMinimumScore($mediaType);
        } catch (ConnectionException|RequestException|UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @return array{automatic_configuration_supported: false, authenticated_url: string, instructions: string}
     */
    public function notificationSetup(ServiceConnection $serviceConnection): array
    {
        $url = route('webhooks.bazarr', ['serviceConnection' => $serviceConnection]);
        $token = $serviceConnection->webhook_token;

        return [
            'automatic_configuration_supported' => false,
            'authenticated_url' => is_string($token) && $token !== ''
                ? $url.'?token='.urlencode($token)
                : $url,
            'instructions' => 'Add this URL as a Bazarr Apprise JSON notification target. Existing notification providers are not changed.',
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $profiles
     * @return list<array<string, mixed>>
     */
    private function languageProfiles(array $profiles): array
    {
        return array_values(array_map(static function (array $profile): array {
            $items = is_array($profile['items'] ?? null) ? $profile['items'] : [];

            return [
                'id' => is_int($profile['profileId'] ?? null) || is_string($profile['profileId'] ?? null)
                    ? $profile['profileId']
                    : 0,
                'name' => is_string($profile['name'] ?? null) ? mb_substr($profile['name'], 0, 150) : 'Unnamed profile',
                'cutoff' => is_int($profile['cutoff'] ?? null) || is_string($profile['cutoff'] ?? null)
                    ? $profile['cutoff']
                    : null,
                'languages' => array_values(array_slice(array_filter(array_map(
                    static fn (mixed $item): ?string => is_array($item) && is_string($item['language'] ?? null)
                        ? mb_substr($item['language'], 0, 20)
                        : null,
                    $items,
                )), 0, 50)),
            ];
        }, array_slice($profiles, 0, 100)));
    }

    /**
     * @return list<array{scope: string, profile_id: int|string}>
     */
    private function profileAssignments(mixed $assignments): array
    {
        if (! is_array($assignments)) {
            return [];
        }

        return array_values(array_filter(array_map(static function (mixed $assignment): ?array {
            if (! is_array($assignment)
                || ! is_string($assignment['scope'] ?? null)
                || (! is_int($assignment['profile_id'] ?? null) && ! is_string($assignment['profile_id'] ?? null))) {
                return null;
            }

            return [
                'scope' => mb_substr($assignment['scope'], 0, 50),
                'profile_id' => $assignment['profile_id'],
            ];
        }, array_slice($assignments, 0, 100))));
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return list<array{id: string, name: string, status: string}>
     */
    private function tasks(array $tasks): array
    {
        return array_values(array_map(static fn (array $task): array => [
            'id' => is_string($task['taskid'] ?? null) ? mb_substr($task['taskid'], 0, 150) : '',
            'name' => is_string($task['name'] ?? null) ? mb_substr($task['name'], 0, 150) : 'Unnamed task',
            'status' => is_string($task['status'] ?? null) ? mb_substr($task['status'], 0, 50) : 'unknown',
        ], array_slice($tasks, 0, 100)));
    }

    /**
     * @param  list<array<string, mixed>>  $providers
     * @return list<array{name: string, status: string, throttled_until: string|null}>
     */
    private function providers(array $providers): array
    {
        return array_values(array_map(static fn (array $provider): array => [
            'name' => is_string($provider['name'] ?? null) ? mb_substr($provider['name'], 0, 100) : 'Unknown provider',
            'status' => is_string($provider['status'] ?? null) ? mb_substr($provider['status'], 0, 50) : 'unknown',
            'throttled_until' => is_string($provider['throttled_until'] ?? null)
                ? mb_substr($provider['throttled_until'], 0, 50)
                : null,
        ], array_slice($providers, 0, 100)));
    }

    /**
     * @param  list<array<string, mixed>>  $notifications
     * @return list<array{id: int|string, name: string, enabled: bool}>
     */
    private function notifications(array $notifications): array
    {
        return array_values(array_map(static fn (array $notification): array => [
            'id' => is_int($notification['id'] ?? null) || is_string($notification['id'] ?? null)
                ? $notification['id']
                : 0,
            'name' => is_string($notification['name'] ?? null) ? mb_substr($notification['name'], 0, 100) : 'Unnamed notification',
            'enabled' => ($notification['enabled'] ?? false) === true,
        ], array_slice($notifications, 0, 100)));
    }

    private function validateWriteValue(string $key, mixed $value): bool|int
    {
        if (in_array($key, ['scheduler_enabled', 'automatic_subtitle_synchronization', 'use_postprocessing'], true)) {
            throw_unless(is_bool($value), InvalidArgumentException::class, sprintf('%s must be a boolean.', $key));

            return $value;
        }

        throw_unless(
            is_int($value) && $value >= 1 && $value <= 168,
            InvalidArgumentException::class,
            'scheduler_interval_hours must be between 1 and 168.',
        );

        return $value;
    }

    private function boundedInteger(mixed $value, int $minimum, int $maximum, int $fallback): int
    {
        return is_int($value) && $value >= $minimum && $value <= $maximum ? $value : $fallback;
    }
}
