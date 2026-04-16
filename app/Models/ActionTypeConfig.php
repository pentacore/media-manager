<?php

declare(strict_types=1);

namespace App\Models;

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
 * @property \Carbon\CarbonImmutable|null $created_at
 * @property \Carbon\CarbonImmutable|null $updated_at
 * @method static \Database\Factories\ActionTypeConfigFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig whereIsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig whereLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig whereRequiresApproval($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig whereType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ActionTypeConfig whereUpdatedAt($value)
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
    #[\Override]
    protected function casts(): array
    {
        return [
            'requires_approval' => 'boolean',
            'is_enabled' => 'boolean',
        ];
    }
}
