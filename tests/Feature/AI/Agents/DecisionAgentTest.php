<?php

declare(strict_types=1);

use App\Ai\Agents\DecisionAgent;
use App\Settings\DecisionAgentSettings;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;

test('DecisionAgent is an Agent with tools but not Conversational', function (): void {
    $agent = new DecisionAgent;

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent)->toBeInstanceOf(HasTools::class);
    expect($agent)->not->toBeInstanceOf(Conversational::class);
});

test('tool list includes ProposeActionTool and read-only context tools', function (): void {
    $shortNames = collect(iterator_to_array((new DecisionAgent)->tools(), false))
        ->map(fn ($tool): string => class_basename($tool))
        ->all();

    expect($shortNames)->toContain('ProposeActionTool');
    expect($shortNames)->toContain('InspectStuckImportTool');
    expect($shortNames)->toContain('ResolveManualImportTool');
    expect($shortNames)->toContain('RemoveStuckDownloadTool');
    expect($shortNames)->toContain('GetServiceStatusTool');
    expect($shortNames)->toContain('GetSeriesTool');
    expect($shortNames)->toContain('GetMovieTool');
});

test('does not expose direct destructive tools — all mutations go through ProposeActionTool', function (): void {
    $shortNames = collect(iterator_to_array((new DecisionAgent)->tools(), false))
        ->map(fn ($tool): string => class_basename($tool))
        ->all();

    expect($shortNames)->not->toContain('DeleteSeriesTool');
    expect($shortNames)->not->toContain('DeleteMovieTool');
});

test('tool list includes Whisparr read tools', function (): void {
    $shortNames = collect(iterator_to_array((new DecisionAgent)->tools(), false))
        ->map(fn ($tool): string => class_basename($tool))
        ->all();

    expect($shortNames)->toContain('SearchItemsTool');
    expect($shortNames)->toContain('GetItemTool');
});

test('does not expose Whisparr write tools', function (): void {
    $shortNames = collect(iterator_to_array((new DecisionAgent)->tools(), false))
        ->map(fn ($tool): string => class_basename($tool))
        ->all();

    expect($shortNames)->not->toContain('AddItemTool');
    expect($shortNames)->not->toContain('DeleteItemTool');
});

test('model() reads from DecisionAgentSettings', function (): void {
    resolve(DecisionAgentSettings::class)->setModel('gpt-triage');

    expect((new DecisionAgent)->model())->toBe('gpt-triage');
});

test('instructions reference ProposeActionTool', function (): void {
    expect((string) (new DecisionAgent)->instructions())->toContain('ProposeActionTool');
});
