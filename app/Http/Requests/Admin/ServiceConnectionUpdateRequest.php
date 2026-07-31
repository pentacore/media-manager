<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\ServiceType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ServiceConnectionUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', ServiceType::validationRule()],
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'external_url' => ['nullable', 'url', 'max:500'],
            // Secrets are optional on update: blank means "keep existing value".
            // The controller filters empty strings so they never overwrite state.
            'api_key' => ['nullable', 'string', 'max:500'],
            'webhook_token' => ['nullable', 'string', 'min:10', 'max:500'],
            // Disk-display preferences for the Service Health page. Only
            // meaningful for sonarr/radarr connections, but accepted on any
            // type so the form can store/restore the picker state.
            'disk_mode' => ['nullable', 'string', 'in:all,selected,sum'],
            'disk_paths' => ['nullable', 'array'],
            'disk_paths.*' => ['string', 'max:500'],
            // disk_display is a path → metric map. The "sum" pseudo-path
            // controls the aggregated row when disk_mode=sum. Allowed
            // values: free / used / both.
            'disk_display' => ['nullable', 'array'],
            'disk_display.*' => ['string', 'in:free,used,both'],
            // SABnzbd-only: list of category names whose queue/history
            // rows should be hidden everywhere in the app. Other types
            // can submit it freely; the controller only persists it for
            // the matching connection types.
            'hidden_categories' => ['nullable', 'array'],
            'hidden_categories.*' => ['string', 'max:100'],
            // Sonarr-only: root folders are scoped to this connection, so the
            // connection ID is deliberately not accepted from the browser.
            'sonarr_root_folders' => ['nullable', 'array', 'max:100'],
            'sonarr_root_folders.*' => ['required', 'array:root_folder_id,path,scope'],
            'sonarr_root_folders.*.root_folder_id' => ['required', 'integer', 'min:1'],
            'sonarr_root_folders.*.path' => ['required', 'string', 'max:1000'],
            'sonarr_root_folders.*.scope' => ['nullable', 'string', 'in:anime,tv'],
            // Sonarr/Radarr-only: arr tag labels that opt a series or movie into
            // the automatic subtitle check on import. Labels rather than ids, so
            // a tag deleted and recreated upstream keeps its configuration
            // instead of silently pointing at a dead id.
            //
            // That survival is not unconditional: only labels the instance
            // currently reports get a checkbox, so a stored label missing
            // upstream is dropped by the next save of any field on this page.
            // It survives recreation only if no save happens in between.
            //
            // Entries are `nullable`, not `required`, so the picker can keep the
            // field present with a single empty value when every checkbox is
            // unticked. An unticked checkbox group submits nothing at all, and
            // an absent field means "preserve" — so without that empty entry the
            // selection could never be cleared.
            //
            // ConvertEmptyStringsToNull turns that entry into null before
            // validation, which `required` would reject; SubtitleCheckTagSettings
            // then drops it because it is not a string, leaving an empty list.
            'subtitle_check_tags' => ['nullable', 'array', 'max:50'],
            'subtitle_check_tags.*' => ['nullable', 'string', 'max:100'],
            // Whisparr-only: which API generation this connection speaks.
            'whisparr_version' => ['nullable', 'string', 'in:v2,v3'],
        ];
    }
}
