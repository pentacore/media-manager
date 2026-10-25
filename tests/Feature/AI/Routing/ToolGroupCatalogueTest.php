<?php

declare(strict_types=1);

use App\Ai\Routing\ToolGroup;
use App\Ai\Routing\ToolGroupCatalogue;

test('the replacement-with-sizes need lands on subtitles and replacement', function (): void {
    $matches = resolve(ToolGroupCatalogue::class)->search('release search that returns file sizes for replacing an episode');

    expect($matches[0]['group'])->toBe('subtitles_replacement')
        ->and($matches[0]['label'])->toBe('Subtitles & replacement')
        ->and($matches)->toHaveCount(min(3, count($matches)))
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
