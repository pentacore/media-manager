<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiReasoningLevel;
use Carbon\CarbonImmutable;
use Database\Factories\ChatTemplateFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * A user's saved assistant prompt. `body` holds {{name}} / {{name:parts}}
 * tokens; `variables` configures one form field per distinct name.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $body
 * @property list<array{name: string, label: string, type: string, default: string|null, options: list<string>|null}> $variables
 * @property bool $auto_send
 * @property bool $pinned
 * @property string|null $model_provider
 * @property string|null $model
 * @property AiReasoningLevel|null $reasoning
 * @property CarbonImmutable|null $last_used_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 *
 * @method static ChatTemplateFactory factory($count = null, $state = [])
 * @method static Builder<static>|ChatTemplate newModelQuery()
 * @method static Builder<static>|ChatTemplate newQuery()
 * @method static Builder<static>|ChatTemplate query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'name',
    'body',
    'variables',
    'auto_send',
    'pinned',
    'model_provider',
    'model',
    'reasoning',
])]
#[TypefinderOverrides([
    'variables' => "Array<{ name: string; label: string; type: 'text' | 'number' | 'choice' | 'series' | 'movie'; default: string | null; options: string[] | null }>",
])]
class ChatTemplate extends Model
{
    /** @use HasFactory<ChatTemplateFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'auto_send' => 'boolean',
            'pinned' => 'boolean',
            'reasoning' => AiReasoningLevel::class,
            'last_used_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->user_id === $user->id;
    }
}
