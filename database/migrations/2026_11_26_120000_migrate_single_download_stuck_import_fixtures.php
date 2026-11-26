<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Until 2026-10-16 InspectStuckImportTool took one `download_id` and answered
 * one inspection (`{ok, service, download_id, total, …}`). Since then it takes
 * `download_ids` (a list) and answers `{ok, service, downloads: [...]}`.
 * Benchmark fixtures recorded before the change still carry the old call, so
 * replay's exact argument matching never finds them and every such call
 * counts as a tool miss. This rewrites each of those fixtures to the call and
 * answer the tool gives today for that one id: `download_ids: [id]`, and a
 * successful result wrapped as the single entry of `downloads` (the per-entry
 * shape is the old result unchanged). A failure result (`ok: false`) is the
 * same in both versions and stays. Only fixtures whose tool is exactly
 * `InspectStuckImportTool` and whose arguments have a string `download_id`
 * and no `download_ids` are touched; StuckImportVerdictTool still takes a
 * single `download_id` and is left alone.
 */
return new class extends Migration
{
    private const string TOOL = 'InspectStuckImportTool';

    public function up(): void
    {
        DB::table('benchmark_cases')
            ->where('fixtures', 'like', '%'.self::TOOL.'%')
            ->select(['id', 'fixtures'])
            ->chunkById(200, function (iterable $rows): void {
                foreach ($rows as $row) {
                    $fixtures = json_decode((string) $row->fixtures, true);

                    if (! is_array($fixtures)) {
                        continue;
                    }

                    $changed = false;

                    foreach ($fixtures as $index => $fixture) {
                        $migrated = $this->migrated($fixture);

                        if ($migrated !== null) {
                            $fixtures[$index] = $migrated;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        DB::table('benchmark_cases')->where('id', $row->id)->update([
                            'fixtures' => json_encode($fixtures, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                        ]);
                    }
                }
            });
    }

    /**
     * The fixture in the current call shape, or null when it is not a
     * single-id InspectStuckImportTool fixture.
     *
     * @return array<string, mixed>|null
     */
    private function migrated(mixed $fixture): ?array
    {
        if (! is_array($fixture) || ($fixture['tool'] ?? null) !== self::TOOL) {
            return null;
        }

        $arguments = $fixture['arguments'] ?? null;

        if (! is_array($arguments) || ! is_string($arguments['download_id'] ?? null) || array_key_exists('download_ids', $arguments)) {
            return null;
        }

        $downloadId = $arguments['download_id'];
        $rewritten = [];

        // Keep the argument order, with download_ids where download_id was.
        foreach ($arguments as $key => $value) {
            if ($key === 'download_id') {
                $rewritten['download_ids'] = [$downloadId];
            } else {
                $rewritten[$key] = $value;
            }
        }

        $fixture['arguments'] = $rewritten;

        if (is_string($fixture['result'] ?? null)) {
            $fixture['result'] = $this->migratedResult($fixture['result']);
        }

        return $fixture;
    }

    private function migratedResult(string $result): string
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

    /**
     * Deliberately a no-op: the rewrite can't be undone exactly. A one-id
     * `download_ids` fixture recorded after the tool change looks the same as
     * a migrated one, so a reverse rewrite would also break fixtures that
     * were never migrated, and the old shape only ever produced misses.
     */
    public function down(): void {}
};
