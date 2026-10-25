<?php

declare(strict_types=1);

use App\Ai\Routing\ToolGroup;
use App\Ai\Routing\ToolGroupCatalogue;

test('the replacement-with-sizes need lands on subtitles and replacement', function (): void {
    $matches = resolve(ToolGroupCatalogue::class)->search('release search that returns file sizes for replacing an episode');

    expect($matches[0]['group'])->toBe('subtitles_replacement')
        ->and($matches[0]['label'])->toBe('Subtitles & replacement')
        ->and(count($matches))->toBeLessThanOrEqual(3)
        ->and(count($matches[0]['tools']))->toBeLessThanOrEqual(3)
        ->and($matches[0]['tools'])->not->toBeEmpty();
});

test('unconfigured integrations are not offered', function (): void {
    $toolGroupCatalogue = resolve(ToolGroupCatalogue::class);

    expect(array_column($toolGroupCatalogue->search('search indexers directly'), 'group'))->not->toContain('indexers')
        ->and($toolGroupCatalogue->available())->not->toContain(ToolGroup::Indexers);
});

test('nothing matching returns no groups', function (): void {
    expect(resolve(ToolGroupCatalogue::class)->search('bake a cake'))->toBe([]);
});

test('tools the chat agent does not declare are never listed', function (): void {
    $names = collect(resolve(ToolGroupCatalogue::class)->search('request a subtitle operation'))
        ->pluck('tools')->flatten(1)->pluck('name')->all();

    expect($names)->not->toContain('InspectSubtitleTool', 'SearchSubtitlesTool', 'RequestSubtitleOperationTool');
});

test('a word only in a sub-agent inner tool description still finds its group', function (): void {
    $matches = resolve(ToolGroupCatalogue::class)->search('ambiguity');

    expect($matches[0]['group'])->toBe('subtitles_replacement')
        ->and(array_column($matches[0]['tools'], 'name'))->toContain('InspectMediaFile');
});
