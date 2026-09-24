<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use App\Support\UserPreferences;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Override;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property CarbonImmutable|null $email_verified_at
 * @property string|null $password
 * @property string|null $remember_token
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property string|null $sso_provider
 * @property string|null $sso_id
 * @property UserRole $role
 * @property string|null $avatar_url
 * @property array<string, mixed>|null $preferences
 * @property string|null $ntfy_topic
 * @property string|null $discord_webhook_url
 * @property string|null $telegram_chat_id
 * @property string|null $webhook_url
 * @property string|null $webhook_secret
 * @property CarbonImmutable|null $invite_accepted_at
 * @property-read Collection<int, ActivityLog> $activityLogs
 * @property-read int|null $activity_logs_count
 * @property-read Collection<int, EmbyUserLink> $embyUserLinks
 * @property-read int|null $emby_user_links_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 *
 * @method static UserFactory factory($count = null, $state = [])
 * @method static Builder<static>|User newModelQuery()
 * @method static Builder<static>|User newQuery()
 * @method static Builder<static>|User query()
 * @method static Builder<static>|User whereAvatarUrl($value)
 * @method static Builder<static>|User whereCreatedAt($value)
 * @method static Builder<static>|User whereEmail($value)
 * @method static Builder<static>|User whereEmailVerifiedAt($value)
 * @method static Builder<static>|User whereId($value)
 * @method static Builder<static>|User whereName($value)
 * @method static Builder<static>|User wherePassword($value)
 * @method static Builder<static>|User wherePreferences($value)
 * @method static Builder<static>|User whereRememberToken($value)
 * @method static Builder<static>|User whereRole($value)
 * @method static Builder<static>|User whereSsoId($value)
 * @method static Builder<static>|User whereSsoProvider($value)
 * @method static Builder<static>|User whereTwoFactorConfirmedAt($value)
 * @method static Builder<static>|User whereTwoFactorRecoveryCodes($value)
 * @method static Builder<static>|User whereTwoFactorSecret($value)
 * @method static Builder<static>|User whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[Fillable(['name', 'email', 'password', 'sso_provider', 'sso_id', 'role', 'avatar_url', 'preferences', 'ntfy_topic', 'discord_webhook_url', 'telegram_chat_id', 'webhook_url', 'webhook_secret', 'invite_accepted_at'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'discord_webhook_url', 'webhook_url', 'webhook_secret'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasFactory;
    use Notifiable;
    use TwoFactorAuthenticatable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'two_factor_confirmed_at' => 'datetime',
            'invite_accepted_at' => 'datetime',
            'preferences' => 'array',
            'discord_webhook_url' => 'encrypted',
            'webhook_url' => 'encrypted',
            'webhook_secret' => 'encrypted',
        ];
    }

    /**
     * @return array{
     *     time_format: '12h'|'24h',
     *     date_format: 'iso'|'us'|'eu'|'long',
     *     timezone: string,
     *     first_day_of_week: int,
     *     show_relative_time: bool
     * }
     */
    public function resolvedPreferences(): array
    {
        return UserPreferences::withDefaults($this->preferences);
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function isMember(): bool
    {
        return $this->role->isAtLeast(UserRole::Member);
    }

    /**
     * Ntfy routing: the per-user topic pushes are published to. Null or
     * empty means the ntfy channel skips this user.
     */
    public function routeNotificationForNtfy(): ?string
    {
        return $this->ntfy_topic;
    }

    /** Discord routing: the user's own webhook URL. Null/empty skips the channel. */
    public function routeNotificationForDiscord(): ?string
    {
        return $this->discord_webhook_url !== '' ? $this->discord_webhook_url : null;
    }

    /** Telegram routing: the user's chat id (bot token is global config). */
    public function routeNotificationForTelegram(): ?string
    {
        return $this->telegram_chat_id !== '' ? $this->telegram_chat_id : null;
    }

    /**
     * Webhook routing: URL plus optional HMAC secret. Null when no URL is set.
     *
     * @return array{url: string, secret: ?string}|null
     */
    public function routeNotificationForWebhook(): ?array
    {
        if (! is_string($this->webhook_url) || $this->webhook_url === '') {
            return null;
        }

        return ['url' => $this->webhook_url, 'secret' => $this->webhook_secret];
    }

    /**
     * @return HasMany<EmbyUserLink, $this>
     */
    public function embyUserLinks(): HasMany
    {
        return $this->hasMany(EmbyUserLink::class);
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
