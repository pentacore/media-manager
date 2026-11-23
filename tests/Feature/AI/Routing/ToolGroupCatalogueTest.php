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

test('at most three groups are returned even when more match', function (): void {
    $toolGroupCatalogue = resolve(ToolGroupCatalogue::class);

    // "request" appears in tools of downloads, library changes, requests and
    // subtitles & replacement: four matching groups.
    expect($toolGroupCatalogue->search('request'))->toHaveCount(3);
});

test('at most three tools are listed per group even when more match', function (): void {
    $requestTools = array_filter(
        ToolGroup::Requests->toolClasses(),
        static fn (string $class): bool => str_contains(strtolower(class_basename($class)), 'request'),
    );

    $matches = collect(resolve(ToolGroupCatalogue::class)->search('request'))->keyBy('group');

    expect(count($requestTools))->toBeGreaterThan(3)
        ->and($matches['requests']['tools'])->toHaveCount(3);
});

test('a need word in the group label ranks that group first on an otherwise equal score', function (): void {
    $groups = array_column(resolve(ToolGroupCatalogue::class)->search('request'), 'group');

    // Downloads precedes requests in the enum and ties on tool score, so only the
    // label bonus can put requests first.
    expect(array_search('downloads', array_column(ToolGroup::cases(), 'value'), true))
        ->toBeLessThan(array_search('requests', array_column(ToolGroup::cases(), 'value'), true))
        ->and($groups[0])->toBe('requests');
});
