<?php

declare(strict_types=1);

namespace App\Services\Benchmark;

/**
 * Until 2026-10-16 InspectStuckImportTool took one `download_id` and answered
 * one inspection (`{ok, service, download_id, total, …}`). Since then it takes
 * `download_ids` and answers `{ok, service, downloads: [...]}`. A case drafted
 * from a tool call recorded before the change would carry the old call, which
 * replay's exact argument matching never finds. This rewrites such a fixture
 * to the call and answer the tool gives today for that one id, the same way
 * the 2026_11_26_120000 fixture migration rewrote stored cases.
 *
 * The migration keeps its own copy on purpose: a migration must not depend on
 * app code, and this class goes once no tool call from before 2026-10-16 is
 * left to draft from (see ROADMAP.md).
 */
final class LegacyStuckImportCall
{
    private const string TOOL = 'InspectStuckImportTool';

    /**
     * The fixture in today's call shape: unchanged unless it is a single-id
     * InspectStuckImportTool call. A failure result (`ok: false`) is the same
     * in both versions and stays.
     *
     * @param  array{tool: string, arguments: array<string, mixed>, result: string}  $fixture
     * @return array{tool: string, arguments: array<string, mixed>, result: string}
     */
    public static function current(array $fixture): array
    {
        $arguments = $fixture['arguments'];

        if ($fixture['tool'] !== self::TOOL || ! is_string($arguments['download_id'] ?? null) || array_key_exists('download_ids', $arguments)) {
            return $fixture;
        }

        $rewritten = [];

        // Keep the argument order, with download_ids where download_id was.
        foreach ($arguments as $key => $value) {
            if ($key === 'download_id') {
                $rewritten['download_ids'] = [$arguments['download_id']];
            } else {
                $rewritten[$key] = $value;
            }
        }

        $fixture['arguments'] = $rewritten;
        $fixture['result'] = self::currentResult($fixture['result']);

        return $fixture;
    }

    private static function currentResult(string $result): string
    {
        $decoded = json_decode($result, true);

        if (! is_array($decoded) || ($decoded['ok'] ?? null) !== true || ! array_key_exists('download_id', $decoded) || array_key_exists('downloads', $decoded)) {
            return $result;
        }

        return json_encode(
            ['ok' => true, 'service' => $decoded['service'] ?? null, 'downloads' => [$decoded]],
            JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ) ?: $result;
    }
}
