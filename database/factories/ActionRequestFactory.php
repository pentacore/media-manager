<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ActionRequestStatus;
use App\Models\ActionRequest;
use App\Models\WebhookEvent;
use App\Services\Arr\ArrWriteUnconfirmed;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Http\Client\RequestException;

/**
 * @extends Factory<ActionRequest>
 */
class ActionRequestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'webhook_event_id' => WebhookEvent::factory(),
            'type' => 'delete_series',
            'source_service' => 'emby',
            'target_service' => 'sonarr',
            'status' => ActionRequestStatus::Pending,
            'requires_approval' => true,
            'approved_by' => null,
            'payload' => ['series_id' => fake()->randomNumber()],
            'result' => null,
        ];
    }

    /**
     * A request carrying a stored second-opinion verdict.
     */
    public function secondOpinion(bool $agrees, bool $failed = false, ?string $reason = 'Looks right.', string $engine = 'llm', string $mode = 'hold', ?float $confidence = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'second_opinion' => [
                'engine' => $engine,
                'mode' => $mode,
                'agrees' => $agrees,
                'failed' => $failed,
                'confidence' => $confidence,
                'reason' => $reason,
                'provider' => $engine === 'llm' ? 'openai' : null,
                'model' => $engine === 'llm' ? 'gpt-5-mini' : null,
            ],
        ]);
    }

    public function autoExecute(): static
    {
        return $this->state(fn (array $attributes): array => [
            'requires_approval' => false,
            'status' => ActionRequestStatus::Approved,
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ActionRequestStatus::Completed,
            'result' => ['success' => true],
        ]);
    }

    /**
     * A plain failure: the target refused, so nothing changed and a retry is
     * safe.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ActionRequestStatus::Failed,
            'result' => [
                'success' => false,
                'reason' => 'execution_failed',
                'message' => 'Sonarr answered 404 Not Found.',
                'exception' => RequestException::class,
                'indeterminate' => false,
            ],
        ]);
    }

    /**
     * A failure with an unknown outcome: the write may already have reached
     * the target, so a retry could repeat it.
     */
    public function needsReconciliation(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ActionRequestStatus::Failed,
            'result' => [
                'success' => false,
                'reason' => 'needs_reconciliation',
                'message' => 'Sonarr answered the write with a login page, so whether it was applied is unknown.',
                'exception' => ArrWriteUnconfirmed::class,
                'indeterminate' => true,
            ],
        ]);
    }

    public function described(): static
    {
        return $this->state(fn (array $attributes): array => [
            'title' => 'Delete series "Severance (2022)"',
            'description' => 'Emby reported "Severance" was removed from the library. Sonarr will delete the series and its files from disk.',
            'details' => [
                ['label' => 'Series', 'value' => 'Severance (2022)'],
                ['label' => 'Delete files', 'value' => 'Yes'],
            ],
            'description_verified' => true,
        ]);
    }
}
