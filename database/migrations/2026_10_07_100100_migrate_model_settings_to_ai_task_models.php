<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves the model/provider/reasoning app_settings keys into ai_task_models
 * (one default row per task). Self-contained on purpose: it reads and
 * writes app_settings with the same JSON encoding AppSettings uses and
 * never touches the settings classes, which stop knowing these keys.
 */
return new class extends Migration
{
    /**
     * task => [provider key, model key, reasoning key]
     *
     * @var array<string, array{0: string, 1: string, 2: string|null}>
     */
    private const array MAP = [
        'chat' => ['ai.model_provider', 'ai.model', 'ai.advisor_reasoning_level'],
        'title' => ['ai.title_model_provider', 'ai.title_model', null],
        'file_inspector' => ['ai.sub_agent_model_provider', 'ai.sub_agent_model', null],
        'stuck_download_investigator' => ['ai.sub_agent_model_provider', 'ai.sub_agent_model', null],
        'price_updater' => ['ai.pricing.updater_model_provider', 'ai.pricing.updater_model', null],
        'decision' => ['decision_agent.model_provider', 'decision_agent.model', 'decision_agent.reasoning_level'],
        'failover' => ['ai.failover_provider', 'ai.failover_model', null],
    ];

    /**
     * Where the reasoning backfill comes from when nothing was saved.
     *
     * @var array<string, string>
     */
    private const array REASONING_CONFIG = [
        'chat' => 'mediamanager.ai.advisor_reasoning_level',
        'decision' => 'mediamanager.decision_agent.reasoning_level',
    ];

    public function up(): void
    {
        $existingInstall = DB::table('app_settings')
            ->where(fn ($query) => $query->where('key', 'like', 'ai.%')->orWhere('key', 'like', 'decision_agent.%'))
            ->exists();

        DB::transaction(function () use ($existingInstall): void {
            foreach (self::MAP as $task => [$providerKey, $modelKey, $reasoningKey]) {
                $provider = $this->read($providerKey);
                $model = $this->read($modelKey);
                $reasoning = $reasoningKey === null ? null : $this->read($reasoningKey);

                if ($reasoning === null && $existingInstall && isset(self::REASONING_CONFIG[$task])) {
                    $configured = trim((string) config(self::REASONING_CONFIG[$task], ''));
                    $reasoning = $configured !== '' ? $configured : 'none';
                }

                if ($provider === null && $model === null && $reasoning === null) {
                    continue;
                }

                DB::table('ai_task_models')->insert([
                    'task' => $task,
                    'scope' => 'default',
                    'provider' => $provider,
                    'model' => $model,
                    'reasoning' => $reasoning,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('app_settings')->whereIn('key', $this->allKeys())->delete();
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            foreach (self::MAP as $task => [$providerKey, $modelKey, $reasoningKey]) {
                if ($task === 'stuck_download_investigator') {
                    continue;
                }

                $row = DB::table('ai_task_models')->where('task', $task)->where('scope', 'default')->first();

                if ($row === null) {
                    continue;
                }

                $this->write($providerKey, $row->provider);
                $this->write($modelKey, $row->model);

                if ($reasoningKey !== null) {
                    $this->write($reasoningKey, $row->reasoning);
                }
            }

            DB::table('ai_task_models')->delete();
        });
    }

    private function read(string $key): ?string
    {
        $raw = DB::table('app_settings')->where('key', $key)->value('value');

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode((string) $raw, true);
        $value = is_string($decoded) ? trim($decoded) : null;

        return $value === '' ? null : $value;
    }

    private function write(string $key, ?string $value): void
    {
        if ($value === null) {
            return;
        }

        DB::table('app_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($value), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * @return list<string>
     */
    private function allKeys(): array
    {
        $keys = [];

        foreach (self::MAP as [$providerKey, $modelKey, $reasoningKey]) {
            array_push($keys, $providerKey, $modelKey, ...($reasoningKey === null ? [] : [$reasoningKey]));
        }

        return array_values(array_unique($keys));
    }
};
