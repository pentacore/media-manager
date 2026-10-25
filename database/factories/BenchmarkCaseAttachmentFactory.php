<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AiTask;
use App\Models\BenchmarkCase;
use App\Models\BenchmarkCaseAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<BenchmarkCaseAttachment>
 */
class BenchmarkCaseAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'benchmark_case_id' => BenchmarkCase::factory()->forTask(AiTask::Chat)->judged(),
            'turn' => null,
            'disk' => 'local',
            'path' => fn (array $attributes): string => sprintf('benchmark-cases/%d/%s.png', $attributes['benchmark_case_id'], Str::uuid7()),
            'original_name' => 'screenshot.png',
            'mime_type' => 'image/png',
            'size' => 1024,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (BenchmarkCaseAttachment $benchmarkCaseAttachment): void {
            Storage::disk($benchmarkCaseAttachment->disk)->put(
                $benchmarkCaseAttachment->path,
                $benchmarkCaseAttachment->isImage() ? 'png-bytes' : "line one\n",
            );
        });
    }

    public function image(): static
    {
        return $this->state(fn (array $attributes): array => [
            'original_name' => 'screenshot.png',
            'mime_type' => 'image/png',
            'size' => 1024,
        ]);
    }

    public function text(): static
    {
        return $this->state(fn (array $attributes): array => [
            'path' => fn (array $resolved): string => sprintf('benchmark-cases/%d/%s.log', $resolved['benchmark_case_id'], Str::uuid7()),
            'original_name' => 'download.log',
            'mime_type' => 'text/plain',
            'size' => 64,
        ]);
    }

    public function forTurn(?int $turn): static
    {
        return $this->state(fn (array $attributes): array => ['turn' => $turn]);
    }
}
