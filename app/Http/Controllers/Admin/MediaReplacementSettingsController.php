<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Enums\SeasonPackPolicy;
use App\Enums\SubtitleRuleStrength;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateMediaReplacementSettingsRequest;
use App\Settings\MediaReplacementSettings;
use Illuminate\Http\RedirectResponse;
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
        ]);
    }

    public function update(
        UpdateMediaReplacementSettingsRequest $updateMediaReplacementSettingsRequest,
        MediaReplacementSettings $mediaReplacementSettings,
    ): RedirectResponse {
        $validated = $updateMediaReplacementSettingsRequest->validated();

        $mediaReplacementConfiguration = $validated['media_replacement'];
        $mediaReplacementConfiguration['sonarr_root_folders'] = $mediaReplacementSettings->sonarrRootFolders();
        $mediaReplacementSettings->setConfiguration($mediaReplacementConfiguration);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Media replacement settings updated.')]);

        return to_route('admin.media-replacement.index');
    }
}
