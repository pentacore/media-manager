<?php

declare(strict_types=1);

use App\Ai\Agents\MediaAgent;
use App\Ai\Tools\BaseTool;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;

test('MediaAgent is an Agent + Conversational + HasTools', function (): void {
    $agent = new MediaAgent;

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent)->toBeInstanceOf(Conversational::class);
    expect($agent)->toBeInstanceOf(HasTools::class);
});

test('every tool returned by tools() extends BaseTool', function (): void {
    $tools = iterator_to_array((new MediaAgent)->tools(), false);

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(BaseTool::class);
    }
});

test('tool list includes the Phase-1 tool families', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    $shortNames = $tools->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('GetServiceStatusTool');
    expect($shortNames)->toContain('QueryActivityTool');
    expect($shortNames)->toContain('SearchSeriesTool');
    expect($shortNames)->toContain('DeleteSeriesTool');
    expect($shortNames)->toContain('SearchMoviesTool');
    expect($shortNames)->toContain('NowPlayingTool');
    expect($shortNames)->toContain('SearchCatalogTool');
    expect($shortNames)->toContain('SearchIndexersTool');
});

test('tool list includes the Phase-2 tool families', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    $shortNames = $tools->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('AddSeriesTool');
    expect($shortNames)->toContain('MonitorSeriesTool');
    expect($shortNames)->toContain('SetSeriesQualityProfileTool');
    expect($shortNames)->toContain('AddMovieTool');
    expect($shortNames)->toContain('MonitorMovieTool');
    expect($shortNames)->toContain('SetMovieQualityProfileTool');
    expect($shortNames)->toContain('MarkAsWatchedTool');
    expect($shortNames)->toContain('MarkAsUnwatchedTool');
    expect($shortNames)->toContain('ApproveRequestTool');
    expect($shortNames)->toContain('DeclineRequestTool');
    expect($shortNames)->toContain('ProposeWorkflowTool');
});

test('tool list has all 42 expected tools', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    expect($tools->count())->toBe(42);
});

test('tool list includes the Whisparr tool families', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    $shortNames = $tools->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('SearchItemsTool');
    expect($shortNames)->toContain('GetItemTool');
    expect($shortNames)->toContain('AddItemTool');
    expect($shortNames)->toContain('MonitorItemTool');
    expect($shortNames)->toContain('SetItemQualityProfileTool');
    expect($shortNames)->toContain('DeleteItemTool');
});

test('tool list includes the Phase-3 metadata tool families', function (): void {
    $tools = collect(iterator_to_array((new MediaAgent)->tools(), false));

    $shortNames = $tools->map(fn ($t): string => class_basename($t))->all();

    expect($shortNames)->toContain('TmdbGetTitleTool');
    expect($shortNames)->toContain('TmdbGetSimilarTool');
    expect($shortNames)->toContain('TmdbGetCreditsTool');
    expect($shortNames)->toContain('TraktGetTrendingTool');
    expect($shortNames)->toContain('TraktGetPopularTool');
    expect($shortNames)->toContain('TraktGetListTool');
});

test('model() reads from AiSettings', function (): void {
    resolve(AiSettings::class)->setModel('gpt-4o-mini');

    expect((new MediaAgent)->model())->toBe('gpt-4o-mini');
});

test('instructions mention every registered tool name', function (): void {
    $agent = new MediaAgent;
    $instructions = (string) $agent->instructions();
    $tools = collect(iterator_to_array($agent->tools(), false));

    foreach ($tools as $tool) {
        expect($instructions)->toContain(class_basename($tool));
    }
});
