<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\AiMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAiSettingsRequest;
use App\Models\AiModelPrice;
use App\Settings\AiSettings;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AiSettingsController extends Controller
{
    public function index(AiSettings $aiSettings): Response
    {
        return Inertia::render('Admin/AiSettings/Index', [
            'settings' => [
                'mode' => $aiSettings->mode()->value,
                'model' => $aiSettings->model(),
            ],
            'modes' => AiMode::mapForSelect(labelKey: 'label'),
            'models' => $this->modelsByConfiguredProvider(),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function modelsByConfiguredProvider(): array
    {
        $configured = collect(config('ai.providers', []))
            // Ollama runs locally and ignores the key; treat it as always configured when listed.
            ->filter(fn (array $cfg, string $name): bool => $name === 'ollama' || filled($cfg['key'] ?? null))
            ->keys()
            ->all();

        if ($configured === []) {
            return [];
        }

        return AiModelPrice::query()
            ->whereIn('provider', $configured)
            ->orderBy('provider')
            ->orderBy('model')
            ->get(['provider', 'model'])
            ->groupBy('provider')
            ->map(fn ($rows): array => $rows->pluck('model')->all())
            ->all();
    }

    public function update(UpdateAiSettingsRequest $updateAiSettingsRequest, AiSettings $aiSettings): RedirectResponse
    {
        $validated = $updateAiSettingsRequest->validated();

        $aiSettings->setMode(AiMode::from($validated['mode']));
        $aiSettings->setModel($validated['model']);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('AI settings updated.')]);

        return to_route('admin.ai-settings.index');
    }
}
