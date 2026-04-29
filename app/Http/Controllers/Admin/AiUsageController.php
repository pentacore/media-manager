<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Services\AiUsage\AiUsageReporting;
use App\Services\AiUsage\Scenario;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiUsageController extends Controller
{
    /**
     * Allowed ?window= values, in display order. Numeric entries map to a
     * `subDays(N)` cutoff; the special `today` key snaps to the local
     * midnight instead.
     */
    private const array WINDOWS = ['today', '24h', '7d', '30d'];

    private const string DEFAULT_WINDOW = '7d';

    public function index(Request $request, AiUsageReporting $aiUsageReporting): Response
    {
        $window = $this->resolveWindow($request);
        $since = $this->cutoffFor($window);
        $scenario = Scenario::fromArray((array) $request->input('scenario', []));

        $page = [
            'window' => $window,
            'totals' => $aiUsageReporting->totals($since),
            'by_model' => $aiUsageReporting->aggregateBy('model', $since),
            'by_provider' => $aiUsageReporting->aggregateBy('provider', $since),
            'recent' => $aiUsageReporting->recentInvocations($since),
            'priced_models' => AiModelPrice::query()
                ->orderBy('provider')
                ->orderBy('model')
                ->get(['provider', 'model', 'input_per_mtok', 'output_per_mtok', 'cache_read_per_mtok', 'cache_write_per_mtok', 'reasoning_per_mtok']),
            'scenario' => $scenario?->toArray(),
            'windows' => self::WINDOWS,
        ];

        if ($scenario instanceof Scenario) {
            $page['scenario_totals'] = $aiUsageReporting->totals($since, $scenario);
            $page['scenario_by_model'] = $aiUsageReporting->aggregateBy('model', $since, $scenario);
            $page['scenario_by_provider'] = $aiUsageReporting->aggregateBy('provider', $since, $scenario);
            $page['scenario_recent'] = $aiUsageReporting->recentInvocations($since, $scenario);
        }

        return Inertia::render('Admin/AiUsage/Index', $page);
    }

    /**
     * Detail payload for the row drill-down modal. Returns plain JSON
     * (fetched from the page, not Inertia-driven) because the modal
     * surfaces alongside the index without a route change.
     */
    public function show(Request $request, AiUsageRecord $aiUsageRecord, AiUsageReporting $aiUsageReporting): JsonResponse
    {
        $scenario = Scenario::fromArray((array) $request->input('scenario', []));

        return new JsonResponse($aiUsageReporting->invocationDetail($aiUsageRecord, $scenario));
    }

    /**
     * Retroactively price an invocation by copying rates from a catalog
     * entry. The picked model's rates are stamped onto the row and
     * price_source flips to 'assigned' so the cost expression uses them
     * instead of (or in addition to) the live catalog match.
     */
    public function assignPrice(Request $request, AiUsageRecord $aiUsageRecord): RedirectResponse
    {
        $validated = $request->validate([
            'provider' => ['required', 'string'],
            'model' => ['required', 'string'],
        ]);

        $aiModelPrice = AiModelPrice::query()
            ->where('provider', $validated['provider'])
            ->where('model', $validated['model'])
            ->firstOrFail();

        $aiUsageRecord->update([
            'input_per_mtok' => $aiModelPrice->input_per_mtok,
            'output_per_mtok' => $aiModelPrice->output_per_mtok,
            'cache_read_per_mtok' => $aiModelPrice->cache_read_per_mtok,
            'cache_write_per_mtok' => $aiModelPrice->cache_write_per_mtok,
            'reasoning_per_mtok' => $aiModelPrice->reasoning_per_mtok,
            'price_source' => 'assigned',
        ]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Pricing assigned from :provider/:model.', [
                'provider' => $aiModelPrice->provider,
                'model' => $aiModelPrice->model,
            ]),
        ]);

        return back();
    }

    /**
     * Stream the priced invocation rows for the active window as CSV.
     * Honours the same window + optional scenario as index() so the file
     * mirrors the on-screen totals.
     */
    public function export(Request $request, AiUsageReporting $aiUsageReporting): StreamedResponse
    {
        $window = $this->resolveWindow($request);
        $since = $this->cutoffFor($window);
        $scenario = Scenario::fromArray((array) $request->input('scenario', []));

        $rows = $aiUsageReporting->recentInvocations($since, $scenario, 10_000);

        $filename = sprintf('ai-usage-%s-%s.csv', $window, now()->format('Ymd-His'));

        return new StreamedResponse(function () use ($rows): void {
            $handle = fopen('php://output', 'wb');

            fputcsv($handle, [
                'id', 'created_at', 'user', 'provider', 'model',
                'prompt_tokens', 'completion_tokens', 'tool_calls',
                'total_tokens', 'cost_usd', 'status',
            ],
                escape: '\\');

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $row->id,
                    $row->created_at,
                    $row->user_name ?? '—',
                    $row->provider,
                    $row->model,
                    $row->prompt_tokens,
                    $row->completion_tokens,
                    $row->tool_calls_count,
                    $row->total_tokens,
                    number_format((float) $row->cost, 6, '.', ''),
                    $row->status,
                ],
                    escape: '\\');
            }

            fclose($handle);
        }, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => sprintf('attachment; filename="%s"', $filename),
            'Cache-Control' => 'no-store',
        ]);
    }

    private function resolveWindow(Request $request): string
    {
        $window = $request->string('window', self::DEFAULT_WINDOW)->value();

        return in_array($window, self::WINDOWS, true) ? $window : self::DEFAULT_WINDOW;
    }

    private function cutoffFor(string $window): CarbonImmutable
    {
        return match ($window) {
            'today' => CarbonImmutable::today(),
            '24h' => CarbonImmutable::now()->subDay(),
            '7d' => CarbonImmutable::now()->subDays(7),
            '30d' => CarbonImmutable::now()->subDays(30),
            default => CarbonImmutable::now()->subDays(7),
        };
    }
}
