<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AiToolInvocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $invocation_id
 * @property string|null $tool_invocation_id
 * @property string $tool_class
 * @property string|null $agent_class
 * @property string $status
 * @property int|null $duration_ms
 * @property string|null $error_code
 * @property string|null $tool_name
 * @property array<string, mixed>|null $arguments
 * @property string|null $result
 * @property bool $io_truncated
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|AiToolInvocation newModelQuery()
 * @method static Builder<static>|AiToolInvocation newQuery()
 * @method static Builder<static>|AiToolInvocation query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'invocation_id',
    'tool_invocation_id',
    'tool_class',
    'agent_class',
    'status',
    'duration_ms',
    'error_code',
    'tool_name',
    'arguments',
    'result',
    'io_truncated',
])]
class AiToolInvocation extends Model
{
    /** @use HasFactory<AiToolInvocationFactory> */
    use HasFactory;

    use MassPrunable;

    public const int MAX_IO_BYTES = 32_768;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'arguments' => 'array',
            'io_truncated' => 'boolean',
        ];
    }

    /**
     * Retention window from mediamanager.retention (0 disables pruning).
     */
    public function prunable(): Builder
    {
        $days = (int) config('mediamanager.retention.ai_tool_invocations_days');

        return static::query()->when(
            $days > 0,
            fn (Builder $builder): Builder => $builder->where('created_at', '<', now()->subDays($days)),
            fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
        );
    }
}
