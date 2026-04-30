<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Override;

/**
 * @property int $id
 * @property int $user_id
 * @property string $notification_class
 * @property string $severity
 * @property bool $database
 * @property bool $broadcast
 * @property bool $mail
 * @property bool $ntfy
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 *
 * @method static Builder<static>|NotificationPreference newModelQuery()
 * @method static Builder<static>|NotificationPreference newQuery()
 * @method static Builder<static>|NotificationPreference query()
 *
 * @mixin \Eloquent
 */
#[Fillable([
    'user_id',
    'notification_class',
    'severity',
    'database',
    'broadcast',
    'mail',
    'ntfy',
])]
class NotificationPreference extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'database' => 'boolean',
            'broadcast' => 'boolean',
            'mail' => 'boolean',
            'ntfy' => 'boolean',
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
