<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ActivityLogCategory;
use App\Observers\ActivityLogObserver;
use App\Support\Abilities;
use Carbon\CarbonImmutable;
use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Override;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int|null $service_connection_id
 * @property int|null $webhook_event_id
 * @property ActivityLogCategory $category
 * @property string $action
 * @property string|null $subject_type
 * @property int|null $subject_id
 * @property string $description
 * @property array<array-key, mixed>|null $metadata
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read ServiceConnection|null $serviceConnection
 * @property-read WebhookEvent|null $webhookEvent
 * @property-read Model|\Eloquent|null $subject
 * @property-read User|null $user
 *
 * @method static ActivityLogFactory factory($count = null, $state = [])
 * @method static Builder<static>|ActivityLog newModelQuery()
 * @method static Builder<static>|ActivityLog newQuery()
 * @method static Builder<static>|ActivityLog query()
 * @method static Builder<static>|ActivityLog visibleTo(?User $user)
 * @method static Builder<static>|ActivityLog whereAction($value)
 * @method static Builder<static>|ActivityLog whereCategory($value)
 * @method static Builder<static>|ActivityLog whereCreatedAt($value)
 * @method static Builder<static>|ActivityLog whereDescription($value)
 * @method static Builder<static>|ActivityLog whereId($value)
 * @method static Builder<static>|ActivityLog whereMetadata($value)
 * @method static Builder<static>|ActivityLog whereServiceConnectionId($value)
 * @method static Builder<static>|ActivityLog whereSubjectId($value)
 * @method static Builder<static>|ActivityLog whereSubjectType($value)
 * @method static Builder<static>|ActivityLog whereUpdatedAt($value)
 * @method static Builder<static>|ActivityLog whereUserId($value)
 *
 * @mixin \Eloquent
 */
#[ObservedBy(ActivityLogObserver::class)]
#[Fillable(['user_id', 'service_connection_id', 'webhook_event_id', 'category', 'action', 'subject_type', 'subject_id', 'description', 'metadata'])]
#[TypefinderOverrides(['metadata' => 'Record<string|number, any> | null'])]
class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    use MassPrunable;

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'category' => ActivityLogCategory::class,
            'metadata' => 'array',
        ];
    }

    /**
     * `description` is varchar(255). Every writer (controllers, jobs,
     * webhook handlers, the ActionRequest logger, AuditLogger) goes through
     * this, so a long title or reason is cut here instead of failing the
     * insert. Postgres counts varchar length in characters, so the cut is
     * by character (mb_substr), not by display width: a CJK or emoji
     * description keeps its full 255 characters.
     *
     * @return Attribute<string, string|null>
     */
    protected function description(): Attribute
    {
        return Attribute::make(set: static fn (?string $value): ?string => $value === null || mb_strlen($value) <= 255 ? $value : mb_substr($value, 0, 252).'...');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<ServiceConnection, $this>
     */
    public function serviceConnection(): BelongsTo
    {
        return $this->belongsTo(ServiceConnection::class);
    }

    /**
     * @return BelongsTo<WebhookEvent, $this>
     */
    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(WebhookEvent::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function isAudit(): bool
    {
        return $this->category === ActivityLogCategory::Audit;
    }

    /**
     * Audit rows are admin-only. Every reader that shows activity to a person
     * or a model (the Activity log page and its export, the dashboard, AI
     * tools) goes through this scope; a null user (AI tools, jobs) never sees
     * audit rows. tests/Unit/Architecture/ActivityLogVisibilityArchTest.php
     * fails on a new reader that skips it.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    protected function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user instanceof User && $user->can(Abilities::ADMIN)) {
            return $query;
        }

        return $query->where('category', ActivityLogCategory::Activity->value);
    }

    /**
     * Activity and audit rows age out on separate windows from
     * mediamanager.retention (0 keeps that category forever).
     */
    public function prunable(): Builder
    {
        $windows = array_filter([
            ActivityLogCategory::Activity->value => (int) config('mediamanager.retention.activity_logs_days'),
            ActivityLogCategory::Audit->value => (int) config('mediamanager.retention.audit_logs_days'),
        ], static fn (int $days): bool => $days > 0);

        if ($windows === []) {
            return static::query()->whereRaw('1 = 0');
        }

        return static::query()->where(function (Builder $builder) use ($windows): void {
            foreach ($windows as $category => $days) {
                $builder->orWhere(fn (Builder $window): Builder => $window
                    ->where('category', $category)
                    ->where('created_at', '<', now()->subDays($days)));
            }
        });
    }
}
