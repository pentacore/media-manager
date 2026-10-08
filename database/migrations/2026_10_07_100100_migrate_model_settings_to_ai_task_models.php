<?php

declare(strict_types=1);

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Moves the model/provider/reasoning app_settings keys into ai_task_models
 * (one default row per task). Self-contained on purpose: it reads and
 * writes app_settings with the same JSON encoding AppSettings uses and
 * never touches the settings classes, which stop knowing these keys.
 *
 * It preserves what each task actually ran on before the upgrade:
 *
 * - A model saved without a provider (settings last saved before 1.26.0)
 *   ran on `config('ai.default')`, so its row gets that provider written
 *   out. Failover is the exception: its provider key is the provider.
 * - The chat and decision agents only ever sent reasoning to OpenAI and
 *   OpenRouter. When a row's effective provider (its own pair; decision
 *   without a model follows the chat pair; chat without a model runs on
 *   `config('ai.default')`) is one of those two, the saved level is kept,
 *   or on an existing install backfilled from config, else `none`. Any
 *   other provider never received a reasoning parameter, so the row gets
 *   `provider_default`, saved level or not.
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

    /**
     * Providers the old chat and decision agents sent reasoning to.
     *
     * @var list<string>
     */
    private const array REASONING_PROVIDERS = ['openai', 'openrouter'];

    public function up(): void
    {
        $existingInstall = DB::table('app_settings')
            ->where(fn (Builder $query) => $query->where('key', 'like', 'ai.%')->orWhere('key', 'like', 'decision_agent.%'))
            ->exists();

        DB::transaction(function () use ($existingInstall): void {
            $saved = [];

            foreach (self::MAP as $task => [$providerKey, $modelKey, $reasoningKey]) {
                $saved[$task] = [
                    $this->read($providerKey),
                    $this->read($modelKey),
                    $reasoningKey === null ? null : $this->read($reasoningKey),
                ];
            }

            $chatProvider = $this->effectiveProvider($saved['chat'][0], $saved['chat'][1]) ?? $this->defaultProvider();
            $effectiveProviders = [
                'chat' => $chatProvider,
                'decision' => $this->effectiveProvider($saved['decision'][0], $saved['decision'][1]) ?? $chatProvider,
            ];

            foreach ($saved as $task => [$provider, $model, $reasoning]) {
                if (isset($effectiveProviders[$task])) {
                    $reasoning = $this->reasoningFor($task, $reasoning, $effectiveProviders[$task], $existingInstall);
                }

                if ($provider === null && $model === null && $reasoning === null) {
                    continue;
                }

                if ($provider === null && $model !== null && $task !== 'failover') {
                    $provider = $this->defaultProvider();
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

    /**
     * Restores the old keys from the default rows. A provider_default level
     * writes no reasoning key: the old agents would send it as an effort.
     */
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

                if ($reasoningKey !== null && $row->reasoning !== 'provider_default') {
                    $this->write($reasoningKey, $row->reasoning);
                }
            }

            DB::table('ai_task_models')->delete();
        });
    }

    /**
     * The provider a saved pair ran on, or null when no model was saved.
     */
    private function effectiveProvider(?string $provider, ?string $model): ?string
    {
        if ($model === null) {
            return null;
        }

        return $provider ?? $this->defaultProvider();
    }

    /**
     * The level that keeps the old behaviour: the old agents only sent
     * reasoning to OpenAI and OpenRouter, so every other provider gets
     * provider_default; for those two the saved level wins, then (on an
     * existing install) the config level, then none.
     */
    private function reasoningFor(string $task, ?string $saved, string $effectiveProvider, bool $existingInstall): ?string
    {
        if (! in_array($effectiveProvider, self::REASONING_PROVIDERS, true)) {
            return $saved !== null || $existingInstall ? 'provider_default' : null;
        }

        if ($saved !== null || ! $existingInstall) {
            return $saved;
        }

        $configured = trim((string) config(self::REASONING_CONFIG[$task], ''));

        return $configured !== '' ? $configured : 'none';
    }

    private function defaultProvider(): string
    {
        return (string) config('ai.default', 'openai');
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
