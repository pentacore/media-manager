<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\AiUsage\AiUsageReporting;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

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

        return Inertia::render('Admin/AiUsage/Index', [
            'window' => $window,
            'totals' => $aiUsageReporting->totals($since),
            'by_agent' => $aiUsageReporting->aggregateBy('agent_class', $since),
            'by_model' => $aiUsageReporting->aggregateBy('model', $since),
            'by_provider' => $aiUsageReporting->aggregateBy('provider', $since),
            'recent' => $aiUsageReporting->recentInvocations($since),
        ]);
    }
}
