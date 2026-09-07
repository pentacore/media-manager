<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\NotificationSeverity;
use App\Enums\PushChannelType;
use Carbon\CarbonImmutable;
use Database\Factories\NotificationDestinationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Override;

/**
 * A global push destination configured by an admin. Being Notifiable lets
 * AdminNotifier hand it to Notification::send() next to the admin users;
 * PreferenceResolver then picks exactly this row's channel when the
 * notification severity meets min_severity.
 *
 * @property int $id
 * @property PushChannelType $channel
 * @property string $label
 * @property array<string, string|null> $config
 * @property bool $is_enabled
 * @property NotificationSeverity $min_severity
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @method static Builder<static>|NotificationDestination newModelQuery()
 * @method static Builder<static>|NotificationDestination newQuery()
 * @method static Builder<static>|NotificationDestination query()
 * @method static Builder<static>|NotificationDestination enabled()
 *
 * @mixin \Eloquent
 */
#[Fillable(['channel', 'label', 'config', 'is_enabled', 'min_severity'])]
#[Hidden(['config'])]
class NotificationDestination extends Model
{
    /** @use HasFactory<NotificationDestinationFactory> */
    use HasFactory;

    use Notifiable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'channel' => PushChannelType::class,
            'config' => 'encrypted:array',
            'is_enabled' => 'boolean',
            'min_severity' => NotificationSeverity::class,
        ];
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function accepts(string $severity): bool
    {
        return $this->is_enabled && $this->min_severity->atLeast($severity);
    }

    public function routeNotificationForNtfy(): ?string
    {
        return $this->channel === PushChannelType::Ntfy ? $this->configValue('topic') : null;
    }

    public function routeNotificationForDiscord(): ?string
    {
        return $this->channel === PushChannelType::Discord ? $this->configValue('url') : null;
    }

    public function routeNotificationForTelegram(): ?string
    {
        return $this->channel === PushChannelType::Telegram ? $this->configValue('chat_id') : null;
    }

    /**
     * @return array{url: string, secret: ?string}|null
     */
    public function routeNotificationForWebhook(): ?array
    {
        $url = $this->channel === PushChannelType::Webhook ? $this->configValue('url') : null;

        return $url === null ? null : ['url' => $url, 'secret' => $this->configValue('secret')];
    }

    /**
     * Masked preview of the primary destination field for admin listings:
     * never the full URL / chat id, only its tail.
     */
    public function configHint(): ?string
    {
        $primary = $this->configValue($this->channel->configKeys()[0]);

        return $primary === null ? null : '…'.Str::substr($primary, -4);
    }

    private function configValue(string $key): ?string
    {
        $value = $this->config[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
