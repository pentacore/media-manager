<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Detects MediaManager's supported Bazarr operations from a Swagger document.
 */
final class BazarrCapabilityRegistry
{
    /**
     * @var array<string, list<array{0: string, 1: string}>>
     */
    private const array REQUIREMENTS = [
        'inventory' => [['/episodes', 'get'], ['/movies', 'get']],
        'wanted' => [['/episodes/wanted', 'get'], ['/movies/wanted', 'get']],
        'history' => [['/episodes/history', 'get'], ['/movies/history', 'get']],
        'manual_search' => [['/providers/episodes', 'get'], ['/providers/movies', 'get']],
        'best_download' => [['/episodes/subtitles', 'patch'], ['/movies/subtitles', 'patch']],
        'exact_download' => [['/providers/episodes', 'post'], ['/providers/movies', 'post']],
        'upload' => [['/episodes/subtitles', 'post'], ['/movies/subtitles', 'post']],
        'delete' => [['/episodes/subtitles', 'delete'], ['/movies/subtitles', 'delete']],
        'sync' => [['/subtitles', 'patch']],
        'translate' => [['/subtitles', 'patch']],
        // Media actions (scan disk, search missing, sync) are writes on the media
        // collections themselves, advertised independently of the inventory reads.
        'episode_media_action' => [['/series', 'patch']],
        'movie_media_action' => [['/movies', 'patch']],
        'tasks' => [['/system/tasks', 'get'], ['/system/tasks', 'post']],
        'language_profiles' => [['/system/languages/profiles', 'get']],
        'settings_adapter' => [['/system/settings', 'get'], ['/system/settings', 'post']],
        'notification_adapter' => [['/system/notifications', 'get'], ['/system/notifications', 'post'], ['/system/notifications', 'patch']],
    ];

    /**
     * @param  array<string, mixed>  $swagger
     * @return array<string, bool>
     *
     * @throws UnexpectedValueException
     */
    public function detect(array $swagger): array
    {
        $availableOperations = $this->availableOperations($swagger);
        $capabilities = $this->unavailable();

        foreach (self::REQUIREMENTS as $capability => $requirements) {
            $capabilities[$capability] = array_all(
                $requirements,
                static fn (array $requirement): bool => isset($availableOperations[$requirement[0]][$requirement[1]]),
            );
        }

        return $capabilities;
    }

    /**
     * @return array<string, bool>
     */
    public function unavailable(): array
    {
        return [
            'inventory' => false,
            'wanted' => false,
            'history' => false,
            'manual_search' => false,
            'best_download' => false,
            'exact_download' => false,
            'upload' => false,
            'delete' => false,
            'sync' => false,
            'translate' => false,
            'episode_media_action' => false,
            'movie_media_action' => false,
            'tasks' => false,
            'language_profiles' => false,
            'settings_adapter' => false,
            'notification_adapter' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $swagger
     * @return array<string, array<string, true>>
     */
    private function availableOperations(array $swagger): array
    {
        throw_unless(($swagger['swagger'] ?? null) === '2.0', UnexpectedValueException::class, 'Bazarr capability discovery requires a Swagger 2.0 document.');

        $basePath = $swagger['basePath'] ?? '';
        $paths = $swagger['paths'] ?? null;

        throw_unless(is_string($basePath) && is_array($paths), UnexpectedValueException::class, 'Bazarr Swagger base path and paths are malformed.');
        throw_if($paths !== [] && array_is_list($paths), UnexpectedValueException::class, 'Bazarr Swagger Paths Object must be associative.');

        $availableOperations = [];

        foreach ($paths as $path => $pathItem) {
            throw_unless(is_string($path), UnexpectedValueException::class, 'Bazarr Swagger contains a malformed path key.');

            if (Str::startsWith(Str::lower($path), 'x-')) {
                continue;
            }

            throw_unless(Str::startsWith($path, '/'), UnexpectedValueException::class, 'Bazarr Swagger contains a malformed path key.');
            if (! is_array($pathItem)) {
                continue;
            }

            if ($pathItem !== [] && array_is_list($pathItem)) {
                continue;
            }

            $normalizedPath = $this->normalizePath($basePath, $path);

            foreach ($pathItem as $method => $operation) {
                if (! is_string($method)) {
                    continue;
                }

                $normalizedMethod = Str::lower($method);

                if (! in_array($normalizedMethod, ['get', 'put', 'post', 'delete', 'options', 'head', 'patch'], true)) {
                    continue;
                }

                if ($this->isAvailableOperation($operation)) {
                    $availableOperations[$normalizedPath][$normalizedMethod] = true;
                }
            }
        }

        return $availableOperations;
    }

    private function isAvailableOperation(mixed $operation): bool
    {
        if (! is_array($operation) || array_is_list($operation)) {
            return false;
        }

        $responses = $operation['responses'] ?? null;

        if (! is_array($responses) || $responses === [] || array_is_list($responses)) {
            return false;
        }

        return array_any($responses, fn ($response, int|string $status): bool => $this->isResponseStatus($status) && $this->isResponseDefinition($response));
    }

    private function isResponseStatus(int|string $status): bool
    {
        if ($status === 'default') {
            return true;
        }

        return preg_match('/^\d{3}$/D', (string) $status) === 1;
    }

    private function isResponseDefinition(mixed $response): bool
    {
        if (! is_array($response) || array_is_list($response)) {
            return false;
        }

        $description = $response['description'] ?? null;
        $reference = $response['$ref'] ?? null;

        return is_string($description)
            || (is_string($reference) && trim($reference) !== '');
    }

    private function normalizePath(string $basePath, string $path): string
    {
        $normalizedBasePath = '/'.trim($basePath, '/');
        $normalizedPath = '/'.trim($path, '/');

        if ($normalizedBasePath !== '/' && ! Str::startsWith($normalizedPath.'/', rtrim($normalizedBasePath, '/').'/')) {
            $normalizedPath = rtrim($normalizedBasePath, '/').$normalizedPath;
        }

        if ($normalizedPath === '/api') {
            return '/';
        }

        if (Str::startsWith($normalizedPath, '/api/')) {
            return Str::after($normalizedPath, '/api');
        }

        return $normalizedPath;
    }
}
