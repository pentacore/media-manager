<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * @property string $key
 * @property mixed $value
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|AppSetting newModelQuery()
 * @method static Builder<static>|AppSetting newQuery()
 * @method static Builder<static>|AppSetting query()
 *
 * @mixin \Eloquent
 */
#[Fillable(['key', 'value'])]
#[WithoutIncrementing]
class AppSetting extends Model
{
    use HasFactory;

    #[Override]
    protected $primaryKey = 'key';

    #[Override]
    protected $keyType = 'string';

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }
}
