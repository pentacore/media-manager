<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AiModelPrice;
use App\Services\AiUsage\AiUsageReporting;
use App\Services\AiUsage\Scenario;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiUsageController extends Controller
{
    private const array WINDOWS = [
        '24h' => 1,
        '7d' => 7,
        '30d' => 30,
    ];

    public function index(Request $request, AiUsageReporting $aiUsageReporting): Response
    {
        $window = $request->string('window', '7d')->value();

        if (! array_key_exists($window, self::WINDOWS)) {
            $window = '7d';
        }

        $since = CarbonImmutable::now()->subDays(self::WINDOWS[$window]);
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
     * Stream the priced invocation rows for the active window as CSV.
     * Honours the same window + optional scenario as index() so the file
     * mirrors the on-screen totals.
     */
    public function export(Request $request, AiUsageReporting $aiUsageReporting): StreamedResponse
    {
        $window = $request->string('window', '7d')->value();

        if (! array_key_exists($window, self::WINDOWS)) {
            $window = '7d';
        }

        $since = CarbonImmutable::now()->subDays(self::WINDOWS[$window]);
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
}
