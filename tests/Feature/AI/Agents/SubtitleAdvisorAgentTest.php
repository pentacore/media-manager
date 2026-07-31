<?php

declare(strict_types=1);

use App\Ai\Agents\SubtitleAdvisorAgent;
use App\Settings\AiSettings;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;

test('subtitle advisor is a focused non-conversational agent', function (): void {
    $agent = new SubtitleAdvisorAgent;

    expect($agent)->toBeInstanceOf(Agent::class)
        ->toBeInstanceOf(HasTools::class)
        ->not->toBeInstanceOf(Conversational::class);
});

test('subtitle advisor instructions enforce the automatic-only one-case boundary', function (): void {
    $instructions = (new SubtitleAdvisorAgent)->instructions();

    expect($instructions)
        ->toContain('exactly one subtitle replacement escalation')
        ->toContain('inspection tool once')
        ->toContain('non-null automatic_candidate')
        ->toContain('exactly that fingerprint')
        ->toContain('queue nothing')
        ->toContain('never claim replacement is complete');
});

test('subtitle advisor exposes only its inspection and replacement queue tools', function (): void {
    $reflection = new ReflectionMethod(SubtitleAdvisorAgent::class, 'tools');
    $source = file($reflection->getFileName());
    $body = implode('', array_slice(
        $source,
        $reflection->getStartLine() - 1,
        $reflection->getEndLine() - $reflection->getStartLine() + 1,
    ));

    expect($body)
        ->toContain('InspectSubtitleEscalationTool')
        ->toContain('QueueAutomaticReplacementTool')
        ->not->toContain('DeleteMediaTool')
        ->not->toContain('WebFetchTool')
        ->not->toContain('SearchMediaTool');
});

test('subtitle advisor model reads from AI settings', function (): void {
    resolve(AiSettings::class)->setModel('gpt-advisor');

    expect((new SubtitleAdvisorAgent)->model())->toBe('gpt-advisor');
});
