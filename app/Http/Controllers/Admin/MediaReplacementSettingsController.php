<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SeasonPackPolicy;
use App\Enums\SettingsGroup;
use App\Enums\SubtitleRuleStrength;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMediaReplacementSettingsRequest;
use App\Models\MediaReplacementAttempt;
use App\Services\Audit\AuditLogger;
use App\Services\Audit\SettingsSnapshot;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MediaReplacementSettingsController extends Controller
{
    public function index(MediaReplacementSettings $mediaReplacementSettings): Response
    {
        $mediaReplacementConfiguration = $mediaReplacementSettings->configuration();
        unset($mediaReplacementConfiguration['sonarr_root_folders']);

        return Inertia::render('Admin/MediaReplacement/Index', [
            'settings' => [
                'media_replacement' => $mediaReplacementConfiguration,
            ],
            'seasonPackPolicies' => SeasonPackPolicy::mapForSelect(labelKey: 'label'),
            'subtitleRuleStrengths' => SubtitleRuleStrength::mapForSelect(labelKey: 'label'),
            'conditionFields' => [
                ['value' => 'release_group', 'label' => 'Release group'],
                ['value' => 'subgroup', 'label' => 'Subgroup'],
                ['value' => 'title', 'label' => 'Title token/phrase'],
                ['value' => 'custom_format', 'label' => 'Custom format'],
            ],
            'attentionCount' => MediaReplacementAttempt::unacknowledgedAttentionCount(),
        ]);
    }

    public function update(
        UpdateMediaReplacementSettingsRequest $updateMediaReplacementSettingsRequest,
        MediaReplacementSettings $mediaReplacementSettings,
        SettingsSnapshot $settingsSnapshot,
        AuditLogger $auditLogger,
    ): RedirectResponse {
        $before = $settingsSnapshot->capture(SettingsGroup::MediaReplacement);
        $validated = $updateMediaReplacementSettingsRequest->validated();

        DB::transaction(function () use ($mediaReplacementSettings, $settingsSnapshot, $auditLogger, $before, $validated): void {
            $mediaReplacementConfiguration = $validated['media_replacement'];
            $mediaReplacementConfiguration['sonarr_root_folders'] = $mediaReplacementSettings->sonarrRootFolders();
            $mediaReplacementSettings->setConfiguration($mediaReplacementConfiguration);

            $auditLogger->settingsUpdated(SettingsGroup::MediaReplacement, $before, $settingsSnapshot->capture(SettingsGroup::MediaReplacement));
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Media replacement settings updated.')]);

        return to_route('admin.media-replacement.index');
    }
}
