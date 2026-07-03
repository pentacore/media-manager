<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiProposedWorkflowStatus;
use Carbon\CarbonImmutable;
use Database\Factories\AiProposedWorkflowFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property string $id
 * @property int $user_id
 * @property string|null $conversation_id
 * @property string $rationale
 * @property array<int, array<string, mixed>> $steps
 * @property AiProposedWorkflowStatus $status
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User $user
 *
 * @method static AiProposedWorkflowFactory factory($count = null, $state = [])
 */
#[Fillable(['id', 'user_id', 'conversation_id', 'rationale', 'steps', 'status'])]
#[WithoutIncrementing]
class AiProposedWorkflow extends Model
{
    /** @use HasFactory<AiProposedWorkflowFactory> */
    use HasFactory;

    #[Override]
    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'steps' => 'array',
            'status' => AiProposedWorkflowStatus::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
