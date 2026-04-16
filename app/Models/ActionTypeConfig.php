<?php

declare(strict_types=1);

namespace App\Models;

use Override;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\ActionTypeConfigFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string $label
 * @property string|null $description
 * @property bool $requires_approval
 * @property bool $is_enabled
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @method static ActionTypeConfigFactory factory($count = null, $state = [])
 * @method static Builder<static>|ActionTypeConfig newModelQuery()
 * @method static Builder<static>|ActionTypeConfig newQuery()
 * @method static Builder<static>|ActionTypeConfig query()
 * @method static Builder<static>|ActionTypeConfig whereCreatedAt($value)
 * @method static Builder<static>|ActionTypeConfig whereDescription($value)
 * @method static Builder<static>|ActionTypeConfig whereId($value)
 * @method static Builder<static>|ActionTypeConfig whereIsEnabled($value)
 * @method static Builder<static>|ActionTypeConfig whereLabel($value)
 * @method static Builder<static>|ActionTypeConfig whereRequiresApproval($value)
 * @method static Builder<static>|ActionTypeConfig whereType($value)
 * @method static Builder<static>|ActionTypeConfig whereUpdatedAt($value)
 * @mixin \Eloquent
 */
#[Fillable(['type', 'label', 'description', 'requires_approval', 'is_enabled'])]
class ActionTypeConfig extends Model
{
    /** @use HasFactory<ActionTypeConfigFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
