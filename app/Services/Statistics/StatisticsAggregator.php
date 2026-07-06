<?php

declare(strict_types=1);

namespace App\Services\Statistics;

use App\Enums\HealthStatus;
use App\Settings\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recomputes stat_rollups buckets from the durable event tables. Windows
 * are [from, to) hour-aligned; reruns overwrite the same buckets (put()),
 * so a failed run that never advances the watermark is safely re-covered.
 */
class StatisticsAggregator
{
    public const string WATERMARK_KEY = 'statistics.aggregate_watermark';

    /**
     * Emby stores play position as ticks of 100 nanoseconds; 1e7 ticks = 1s.
     */
    private const float EMBY_TICKS_PER_SECOND = 10_000_000.0;

    /**
     * Base-model normalization: strip a trailing dated variant suffix
     * (e.g. "-2025-09-23") so dated model ids collapse onto their catalog
     * row, matching AiUsageReporting.
     */
    private const string BASE_MODEL_EXPR = "regexp_replace(ai_usage_records.model, '-[0-9]{4}-[0-9]{2}-[0-9]{2}$', '')";

    /**
     * Per-row cost expression reused from AiUsageReporting::costExpression()
     * (no scenario): snapshot rates preferred, catalog rates as fallback.
     */
    private const string COST_EXPR = '
        (
            ai_usage_records.prompt_tokens * COALESCE(ai_usage_records.input_per_mtok, ai_model_prices.input_per_mtok, 0)
            + ai_usage_records.completion_tokens * COALESCE(ai_usage_records.output_per_mtok, ai_model_prices.output_per_mtok, 0)
            + ai_usage_records.cache_read_input_tokens * COALESCE(ai_usage_records.cache_read_per_mtok, ai_model_prices.cache_read_per_mtok, 0)
            + ai_usage_records.cache_write_input_tokens * COALESCE(ai_usage_records.cache_write_per_mtok, ai_model_prices.cache_write_per_mtok, 0)
            + ai_usage_records.reasoning_tokens * COALESCE(ai_usage_records.reasoning_per_mtok, ai_model_prices.reasoning_per_mtok, 0)
        ) / 1000000.0
    ';

    public function __construct(
        private readonly StatsRecorder $statsRecorder,
        private readonly AppSettings $appSettings,
    ) {}

    public function aggregate(?CarbonImmutable $from = null, ?CarbonImmutable $to = null): void
    {
        $to = ($to ?? CarbonImmutable::now('UTC'))->utc()->startOfHour();
        $usingWatermark = $from === null;
        $from = ($from ?? $this->watermark())->utc()->startOfHour();

        if ($from->greaterThanOrEqualTo($to)) {
            return;
        }

        $this->aggregateWatch($from, $to);
        $this->aggregateLibraryAdded($from, $to);
        $this->aggregateActions($from, $to);
        $this->aggregateAgentDecisions($from, $to);
        $this->aggregateAiUsage($from, $to);
        $this->aggregateServiceMetrics($from, $to);

        if ($usingWatermark) {
            $this->appSettings->set(self::WATERMARK_KEY, $to->toIso8601String());
        }
    }

    private function watermark(): CarbonImmutable
    {
        $raw = $this->appSettings->get(self::WATERMARK_KEY);

        return $raw !== null
            ? CarbonImmutable::parse((string) $raw)->utc()
            : CarbonImmutable::now('UTC')->subDay();
    }

    private function aggregateWatch(CarbonImmutable $from, CarbonImmutable $to): void
    {
        foreach (['hour', 'day'] as $period) {
            [$lo, $hi] = $this->periodSpan($from, $to, $period);

            $plays = DB::table('emby_activities')
                ->where('action', 'played')
                ->where('created_at', '>=', $lo)->where('created_at', '<', $hi)
                ->selectRaw('date_trunc(?, created_at) AS bucket, media_type, COUNT(*) AS count', [$period])
                ->groupBy('bucket', 'media_type')
                ->get();

            foreach ($plays as $row) {
                $this->statsRecorder->put(
                    'watch.plays',
                    $period,
                    $this->bucket($row->bucket),
                    ['media_type' => (string) $row->media_type],
                    (int) $row->count,
                );
            }

            $finishes = DB::table('emby_activities')
                ->where('action', 'finished')
                ->where('created_at', '>=', $lo)->where('created_at', '<', $hi)
                ->selectRaw('date_trunc(?, created_at) AS bucket, media_type, COUNT(*) AS count', [$period])
                ->groupBy('bucket', 'media_type')
                ->get();

            foreach ($finishes as $row) {
                $this->statsRecorder->put(
                    'watch.finishes',
                    $period,
                    $this->bucket($row->bucket),
                    ['media_type' => (string) $row->media_type],
                    (int) $row->count,
                );
            }

            $seconds = DB::table('emby_activities')
                ->whereIn('action', ['stopped', 'finished'])
                ->whereNotNull('play_position')
                ->where('created_at', '>=', $lo)->where('created_at', '<', $hi)
                ->selectRaw('date_trunc(?, created_at) AS bucket, emby_user_link_id, COUNT(*) AS count, SUM(play_position) / '.self::EMBY_TICKS_PER_SECOND.' AS seconds', [$period])
                ->groupBy('bucket', 'emby_user_link_id')
                ->get();

            foreach ($seconds as $row) {
                $this->statsRecorder->put(
                    'watch.seconds',
                    $period,
                    $this->bucket($row->bucket),
                    ['emby_user_link_id' => (int) $row->emby_user_link_id],
                    (int) $row->count,
                    (float) $row->seconds,
                );
            }

            $userPlays = DB::table('emby_activities')
                ->where('action', 'played')
                ->where('created_at', '>=', $lo)->where('created_at', '<', $hi)
                ->selectRaw('date_trunc(?, created_at) AS bucket, emby_user_link_id, COUNT(*) AS count', [$period])
                ->groupBy('bucket', 'emby_user_link_id')
                ->get();

            foreach ($userPlays as $row) {
                $this->statsRecorder->put(
                    'watch.user_plays',
                    $period,
                    $this->bucket($row->bucket),
                    ['emby_user_link_id' => (int) $row->emby_user_link_id],
                    (int) $row->count,
                );
            }
        }
    }

    private function aggregateLibraryAdded(CarbonImmutable $from, CarbonImmutable $to): void
    {
        foreach (['hour', 'day'] as $period) {
            [$lo, $hi] = $this->periodSpan($from, $to, $period);

            foreach (['movie' => 'indexed_movies', 'series' => 'indexed_series'] as $kind => $table) {
                $rows = DB::table($table)
                    ->whereNotNull('arr_added_at')
                    ->where('arr_added_at', '>=', $lo)->where('arr_added_at', '<', $hi)
                    ->selectRaw('date_trunc(?, arr_added_at) AS bucket, COUNT(*) AS count', [$period])
                    ->groupBy('bucket')
                    ->get();

                foreach ($rows as $row) {
                    $this->statsRecorder->put(
                        'library.added',
                        $period,
                        $this->bucket($row->bucket),
                        ['kind' => $kind],
                        (int) $row->count,
                    );
                }
            }
        }
    }

    private function aggregateActions(CarbonImmutable $from, CarbonImmutable $to): void
    {
        foreach (['hour', 'day'] as $period) {
            [$lo, $hi] = $this->periodSpan($from, $to, $period);

            $rows = DB::table('action_requests')
                ->where('created_at', '>=', $lo)->where('created_at', '<', $hi)
                ->selectRaw("date_trunc(?, created_at) AS bucket, status, type, COALESCE(origin, 'system') AS origin, COUNT(*) AS count", [$period])
                ->groupBy('bucket', 'status', 'type', 'origin')
                ->get();

            foreach ($rows as $row) {
                $this->statsRecorder->put(
                    'actions.by_status',
                    $period,
                    $this->bucket($row->bucket),
                    ['status' => (string) $row->status, 'type' => (string) $row->type, 'origin' => (string) $row->origin],
                    (int) $row->count,
                );
            }
        }
    }

    private function aggregateAgentDecisions(CarbonImmutable $from, CarbonImmutable $to): void
    {
        foreach (['hour', 'day'] as $period) {
            [$lo, $hi] = $this->periodSpan($from, $to, $period);

            $rows = DB::table('agent_decisions')
                ->where('created_at', '>=', $lo)->where('created_at', '<', $hi)
                ->selectRaw('date_trunc(?, created_at) AS bucket, status, service, COUNT(*) AS count, COALESCE(SUM(actions_count), 0) AS sum', [$period])
                ->groupBy('bucket', 'status', 'service')
                ->get();

            foreach ($rows as $row) {
                $this->statsRecorder->put(
                    'agent.decisions',
                    $period,
                    $this->bucket($row->bucket),
                    ['status' => (string) $row->status, 'service' => (string) $row->service],
                    (int) $row->count,
                    (float) $row->sum,
                );
            }
        }
    }

    private function aggregateAiUsage(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $baseModel = self::BASE_MODEL_EXPR;
        $cost = self::COST_EXPR;

        foreach (['hour', 'day'] as $period) {
            [$lo, $hi] = $this->periodSpan($from, $to, $period);

            $usage = DB::table('ai_usage_records')
                ->leftJoin('ai_model_prices', function ($join): void {
                    $join->on('ai_usage_records.provider', '=', 'ai_model_prices.provider')
                        ->whereRaw(
                            "regexp_replace(ai_usage_records.model, '-[0-9]{4}-[0-9]{2}-[0-9]{2}$', '') = ai_model_prices.model"
                        );
                })
                ->where('ai_usage_records.created_at', '>=', $lo)->where('ai_usage_records.created_at', '<', $hi)
                ->selectRaw("
                    date_trunc(?, ai_usage_records.created_at) AS bucket,
                    ai_usage_records.provider,
                    {$baseModel} AS base_model,
                    ai_usage_records.agent_class,
                    COUNT(*) AS count,
                    COALESCE(SUM({$cost}), 0) AS cost
                ", [$period])
                ->groupByRaw('bucket, ai_usage_records.provider, base_model, ai_usage_records.agent_class')
                ->get();

            foreach ($usage as $row) {
                $this->statsRecorder->put(
                    'ai.usage',
                    $period,
                    $this->bucket($row->bucket),
                    [
                        'provider' => (string) $row->provider,
                        'model' => (string) $row->base_model,
                        'agent_class' => (string) $row->agent_class,
                    ],
                    (int) $row->count,
                    (float) $row->cost,
                );
            }

            $tokens = DB::table('ai_usage_records')
                ->where('created_at', '>=', $lo)->where('created_at', '<', $hi)
                ->selectRaw("
                    date_trunc(?, created_at) AS bucket,
                    provider,
                    regexp_replace(model, '-[0-9]{4}-[0-9]{2}-[0-9]{2}$', '') AS base_model,
                    COUNT(*) AS count,
                    COALESCE(SUM(prompt_tokens + completion_tokens + reasoning_tokens), 0) AS sum
                ", [$period])
                ->groupByRaw('bucket, provider, base_model')
                ->get();

            foreach ($tokens as $row) {
                $this->statsRecorder->put(
                    'ai.tokens',
                    $period,
                    $this->bucket($row->bucket),
                    ['provider' => (string) $row->provider, 'model' => (string) $row->base_model],
                    (int) $row->count,
                    (float) $row->sum,
                );
            }
        }
    }

    private function aggregateServiceMetrics(CarbonImmutable $from, CarbonImmutable $to): void
    {
        $healthy = HealthStatus::Healthy->value;

        foreach (['hour', 'day'] as $period) {
            [$lo, $hi] = $this->periodSpan($from, $to, $period);

            $latency = DB::table('service_metrics')
                ->whereNotNull('latency_ms')
                ->where('recorded_at', '>=', $lo)->where('recorded_at', '<', $hi)
                ->selectRaw('date_trunc(?, recorded_at) AS bucket, service_connection_id, COUNT(*) AS count, SUM(latency_ms) AS sum, MIN(latency_ms) AS min, MAX(latency_ms) AS max', [$period])
                ->groupBy('bucket', 'service_connection_id')
                ->get();

            foreach ($latency as $row) {
                $this->statsRecorder->put(
                    'service.latency_ms',
                    $period,
                    $this->bucket($row->bucket),
                    ['service_connection_id' => (int) $row->service_connection_id],
                    (int) $row->count,
                    (float) $row->sum,
                    (float) $row->min,
                    (float) $row->max,
                );
            }

            $uptime = DB::table('service_metrics')
                ->where('recorded_at', '>=', $lo)->where('recorded_at', '<', $hi)
                ->selectRaw('date_trunc(?, recorded_at) AS bucket, service_connection_id, COUNT(*) AS count, SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) AS sum', [$period, $healthy])
                ->groupBy('bucket', 'service_connection_id')
                ->get();

            foreach ($uptime as $row) {
                $this->statsRecorder->put(
                    'service.uptime_pct',
                    $period,
                    $this->bucket($row->bucket),
                    ['service_connection_id' => (int) $row->service_connection_id],
                    (int) $row->count,
                    (float) $row->sum,
                );
            }
        }
    }

    private function bucket(mixed $raw): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $raw, 'UTC');
    }

    /**
     * Day-period recompute must cover whole days that intersect [from, to),
     * otherwise a mid-day run would overwrite the day bucket with a partial
     * count. Hour periods use the window as-is.
     *
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function periodSpan(CarbonImmutable $from, CarbonImmutable $to, string $period): array
    {
        return $period === 'hour'
            ? [$from, $to]
            : [$from->startOfDay(), $to->subSecond()->endOfDay()->addSecond()];
    }
}
