<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\ActivityLogCategory;
use App\Enums\SettingsGroup;
use App\Models\ActivityLog;
use App\Models\NotificationDestination;
use App\Models\ServiceConnection;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The only writer of `audit` activity rows. Callers pass the raw diff
 * (AuditChanges::between()) and context; masking happens here so a caller
 * cannot forget it. Audit rows are admin-only (the model's visibleTo scope)
 * and broadcast on `activity.audit` only (ActivityLogCreated). The acting
 * user's id and name are also kept in `metadata.actor`, so a row still
 * names its actor after that account is deleted.
 */
final readonly class AuditLogger
{
    /**
     * Secret fields per audited subject beyond the key-name rule, keyed by the
     * subject's morph class or settings group. Discord and generic webhook
     * destination URLs are credentials even though the key is just `url`.
     *
     * @var array<string, list<string>>
     */
    public const array SUBJECT_SECRET_FIELDS = [
        'notification_destinations' => ['config.url'],
        NotificationDestination::class => ['config.url'],
    ];

    /**
     * @param  Model|string|null  $subject  the changed model, or a settings group name for settings rows
     * @param  array<string, array{from: mixed, to: mixed}>  $changes
     * @param  array<string, mixed>  $context
     */
    public function record(string $action, Model|string|null $subject, string $description, array $changes = [], array $context = []): ActivityLog
    {
        $subjectType = $subject instanceof Model ? $subject->getMorphClass() : $subject;
        $secretFields = $subjectType === null ? [] : (self::SUBJECT_SECRET_FIELDS[$subjectType] ?? []);

        $metadata = array_filter([
            'changes' => AuditChanges::mask($changes, $secretFields),
            'context' => AuditChanges::scrub($context),
        ], static fn (array $section): bool => $section !== []);

        // user_id is nulled when the account is deleted; the trail keeps who.
        $actor = Auth::user();

        if ($actor instanceof User) {
            $metadata['actor'] = ['id' => $actor->id, 'name' => $actor->name];
        }

        return ActivityLog::create([
            'category' => ActivityLogCategory::Audit,
            'user_id' => Auth::id(),
            // A deleted connection keeps its id in subject_id; the FK column
            // only ever points at a row that still exists.
            'service_connection_id' => $subject instanceof ServiceConnection && $subject->exists ? $subject->id : null,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subject instanceof Model ? $subject->getKey() : null,
            'description' => $description,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }

    /**
     * One `settings.updated` row per save that changed something; a save that
     * changed nothing writes nothing.
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @param  array<string, mixed>  $context
     */
    public function settingsUpdated(SettingsGroup $settingsGroup, array $before, array $after, array $context = [], ?string $description = null): ?ActivityLog
    {
        $changes = AuditChanges::between($before, $after);

        if ($changes === []) {
            return null;
        }

        return $this->record(
            'settings.updated',
            $settingsGroup->value,
            $description ?? sprintf('Updated %s settings.', $settingsGroup->label()),
            $changes,
            $context,
        );
    }
}
