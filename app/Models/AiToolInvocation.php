<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $invocation_id
 * @property string|null $tool_invocation_id
 * @property string $tool_class
 * @property string|null $agent_class
 * @property string $status
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
])]
class AiToolInvocation extends Model
{
    use HasFactory;
}
