<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
 * @property int $tool_calls_count
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
    'tool_calls_count',
    'user_id',
    'conversation_id',
    'status',
])]
class AiUsageRecord extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
