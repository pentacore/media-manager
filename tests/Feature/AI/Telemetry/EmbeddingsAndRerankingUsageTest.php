<?php

declare(strict_types=1);

use App\Enums\AiUsageKind;
use App\Models\AiModelPrice;
use App\Models\AiUsageRecord;
use App\Models\User;
use App\Services\AiUsage\AiUsageCaller;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\RankedDocument;
use Laravel\Ai\Responses\Data\RerankingUsage;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\EmbeddingsResponse;
use Laravel\Ai\Responses\RerankingResponse;

test('embeddings generation writes an embeddings usage row attributed to the caller', function (): void {
    Embeddings::fake([new EmbeddingsResponse([[0.1, 0.2]], new Usage(42), new Meta('openai', 'text-embedding-3-small'))]);

    resolve(AiUsageCaller::class)->during('App\Services\Search\LibraryEmbedder', fn () => Embeddings::for(['Dune'])->generate());

    $row = AiUsageRecord::where('kind', AiUsageKind::Embeddings)->sole();

    expect($row->agent_class)->toBe('App\Services\Search\LibraryEmbedder')
        ->and($row->provider)->toBe('openai')
        ->and($row->prompt_tokens)->toBe(42)
        ->and($row->completion_tokens)->toBe(0);
});

test('embeddings outside a caller context fall back to a generic label and the signed-in user', function (): void {
    $user = User::factory()->member()->create();
    $this->actingAs($user);
    Embeddings::fake();

    Embeddings::for(['Dune'])->generate();

    $row = AiUsageRecord::where('kind', AiUsageKind::Embeddings)->sole();

    expect($row->agent_class)->toBe('embeddings')
        ->and($row->user_id)->toBe($user->id);
});

test('the caller label is restored once the wrapped call returns', function (): void {
    $aiUsageCaller = resolve(AiUsageCaller::class);

    $aiUsageCaller->during('Outer', fn () => $aiUsageCaller->during('Inner', fn (): null => null));

    expect($aiUsageCaller->current())->toBeNull();
});

test('reranking writes a reranking row with search units priced per thousand', function (): void {
    AiModelPrice::factory()->create(['provider' => 'cohere', 'model' => 'rerank-v3.5', 'input_per_mtok' => 0, 'output_per_mtok' => 0, 'search_unit_per_k' => 2.0]);
    Reranking::fake();

    Reranking::of(['a', 'b'])->rerank('q', provider: 'cohere', model: 'rerank-v3.5');

    $row = AiUsageRecord::where('kind', AiUsageKind::Reranking)->sole();

    expect($row->agent_class)->toBe('reranking')
        ->and($row->search_unit_per_k)->toBe('2.0000')
        ->and($row->search_units)->toBe('0.000');
});

test('reranking records the search units the provider reports', function (): void {
    Reranking::fake([new RerankingResponse(
        [new RankedDocument(index: 0, document: 'a', score: 0.9)],
        new RerankingUsage(0, 1.0),
        new Meta('cohere', 'rerank-v3.5'),
    )]);

    resolve(AiUsageCaller::class)->during('App\Services\Search\SemanticLibrarySearch', fn () => Reranking::of(['a'])->rerank('q', provider: 'cohere', model: 'rerank-v3.5'));

    $row = AiUsageRecord::where('kind', AiUsageKind::Reranking)->sole();

    expect($row->search_units)->toBe('1.000')
        ->and($row->agent_class)->toBe('App\Services\Search\SemanticLibrarySearch');
});
