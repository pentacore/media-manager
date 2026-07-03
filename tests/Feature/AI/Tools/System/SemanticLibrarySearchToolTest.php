<?php

declare(strict_types=1);

use App\Ai\Risk;
use App\Ai\Tools\System\SemanticLibrarySearchTool;
use App\Services\Search\SemanticLibrarySearch;
use Laravel\Ai\Tools\Request;

function bindSemanticSearch(array $return, ?Closure $expectation = null): void
{
    $mock = Mockery::mock(SemanticLibrarySearch::class);
    $expectation
        ? $expectation($mock)
        : $mock->shouldReceive('search')->andReturn($return);

    app()->instance(SemanticLibrarySearch::class, $mock);
}

test('risk is Read', function (): void {
    expect(resolve(SemanticLibrarySearchTool::class)->risk())->toBe(Risk::Read);
});

test('returns the scored results envelope from the service', function (): void {
    $results = [
        ['kind' => 'series', 'id' => 21, 'title' => 'Dark', 'year' => 2017, 'overview' => 'Time travel.', 'score' => 0.95],
        ['kind' => 'movie', 'id' => 11, 'title' => 'Blade Runner', 'year' => 1982, 'overview' => 'Neo-noir.', 'score' => 0.9],
    ];

    bindSemanticSearch([], function ($mock) use ($results): void {
        $mock->shouldReceive('search')
            ->once()
            ->with('moody sci-fi', 10, null)
            ->andReturn(['available' => true, 'results' => $results]);
    });

    $tool = resolve(SemanticLibrarySearchTool::class);
    $decoded = json_decode($tool->handle(new Request(['query' => 'moody sci-fi'])), true);

    expect($decoded['available'])->toBeTrue()
        ->and($decoded['results'])->toHaveCount(2)
        ->and($decoded['results'][0]['title'])->toBe('Dark');
});

test('clamps limit and passes a whitelisted kind through to the service', function (): void {
    bindSemanticSearch([], function ($mock): void {
        $mock->shouldReceive('search')
            ->once()
            ->with('cozy detective shows', 25, 'series')
            ->andReturn(['available' => true, 'results' => []]);
    });

    $tool = resolve(SemanticLibrarySearchTool::class);
    $tool->handle(new Request(['query' => 'cozy detective shows', 'limit' => 999, 'kind' => 'series']));
});

test('ignores an invalid kind by passing null', function (): void {
    bindSemanticSearch([], function ($mock): void {
        $mock->shouldReceive('search')
            ->once()
            ->with('anything', 10, null)
            ->andReturn(['available' => true, 'results' => []]);
    });

    $tool = resolve(SemanticLibrarySearchTool::class);
    $tool->handle(new Request(['query' => 'anything', 'kind' => 'documentary']));
});

test('returns an error envelope when the query is empty', function (): void {
    $mock = Mockery::mock(SemanticLibrarySearch::class);
    $mock->shouldNotReceive('search');
    app()->instance(SemanticLibrarySearch::class, $mock);

    $tool = resolve(SemanticLibrarySearchTool::class);
    $decoded = json_decode($tool->handle(new Request(['query' => '   '])), true);

    expect($decoded['error'])->toBe('empty_query');
});

test('surfaces the unavailable envelope when the service is unavailable', function (): void {
    bindSemanticSearch(['available' => false, 'results' => []]);

    $tool = resolve(SemanticLibrarySearchTool::class);
    $decoded = json_decode($tool->handle(new Request(['query' => 'moody sci-fi'])), true);

    expect($decoded['available'])->toBeFalse()
        ->and($decoded['message'])->toContain('unavailable');
});
