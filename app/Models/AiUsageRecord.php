<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property string $invocation_id
 * @property string|null $agent_class
 * @property string|null $provider
 * @property string|null $model
 * @property int $prompt_tokens
 * @property int $completion_tokens
 * @property int $cache_read_input_tokens
 * @property int $cache_write_input_tokens
 * @property int $reasoning_tokens
 * @property string|null $response_text
 * @property int $tool_calls_count
 * @property string|null $input_per_mtok
 * @property string|null $output_per_mtok
 * @property string|null $cache_read_per_mtok
 * @property string|null $cache_write_per_mtok
 * @property string|null $reasoning_per_mtok
 * @property bool $is_batch
 * @property string|null $price_source
 * @property int|null $user_id
 * @property string|null $conversation_id
 * @property string $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|AiUsageRecord newModelQuery()
 * @method static Builder<static>|AiUsageRecord newQuery()
 * @method static Builder<static>|AiUsageRecord query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'invocation_id',
    'agent_class',
    'provider',
    'model',
    'prompt_tokens',
    'completion_tokens',
    'cache_read_input_tokens',
    'cache_write_input_tokens',
    'reasoning_tokens',
    'response_text',
    'tool_calls_count',
    'input_per_mtok',
    'output_per_mtok',
    'cache_read_per_mtok',
    'cache_write_per_mtok',
    'reasoning_per_mtok',
    'is_batch',
    'price_source',
    'user_id',
    'conversation_id',
    'status',
])]
class AiUsageRecord extends Model
{
    use HasFactory;
    use MassPrunable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'input_per_mtok' => 'decimal:4',
            'output_per_mtok' => 'decimal:4',
            'cache_read_per_mtok' => 'decimal:4',
            'cache_write_per_mtok' => 'decimal:4',
            'reasoning_per_mtok' => 'decimal:4',
            'is_batch' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Retention window from mediamanager.retention (0 disables pruning).
     */
    public function prunable(): Builder
    {
        $days = (int) config('mediamanager.retention.ai_usage_records_days');

        return static::query()->when(
            $days > 0,
            fn (Builder $builder): Builder => $builder->where('created_at', '<', now()->subDays($days)),
            fn (Builder $builder): Builder => $builder->whereRaw('1 = 0'),
        );
    }
}
