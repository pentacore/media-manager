<?php

declare(strict_types=1);

use App\Enums\AiUsageKind;
use App\Models\AiUsageRecord;
use App\Support\UpstreamErrorText;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

test('a failed embeddings call bills a failed row with its error', function (): void {
    Embeddings::fake(fn () => throw new RuntimeException('embeddings down'));

    expect(fn () => Embeddings::for(['Dune'])->generate())->toThrow(RuntimeException::class);

    $row = AiUsageRecord::where('kind', AiUsageKind::Embeddings)->sole();

    expect($row->status)->toBe('failed')
        ->and($row->error_message)->toBe('embeddings down')
        ->and($row->agent_class)->toBe('embeddings')
        ->and($row->prompt_tokens)->toBe(0)
        ->and($row->completion_tokens)->toBe(0);
});

test('a failed rerank call bills a failed row with its error', function (): void {
    Reranking::fake(fn () => throw new RuntimeException('rerank down'));

    expect(fn () => Reranking::of(['a', 'b'])->rerank('q'))->toThrow(RuntimeException::class);

    $row = AiUsageRecord::where('kind', AiUsageKind::Reranking)->sole();

    expect($row->status)->toBe('failed')
        ->and($row->error_message)->toBe('rerank down')
        ->and($row->agent_class)->toBe('reranking');
});

test("a failed call's error is sanitized", function (): void {
    $message = 'read /etc/ai/key.json from https://gw.example/v1?token=abc123';
    Embeddings::fake(fn () => throw new RuntimeException($message));

    expect(fn () => Embeddings::for(['Dune'])->generate())->toThrow(RuntimeException::class);

    expect(AiUsageRecord::where('kind', AiUsageKind::Embeddings)->sole()->error_message)
        ->toBe(UpstreamErrorText::sanitize($message, 2000))
        ->toBe('read [redacted path] from https://gw.example/v1?[redacted]');
});
