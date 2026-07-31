<?php

declare(strict_types=1);

namespace App\Http\Requests\Bazarr;

use App\Enums\UserRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

#[TypefinderOverrides([
    'automation' => '{ enabled: boolean; reconciliation_interval_minutes: number; grace_hours: { anime: number; tv: number; movie: number }; probe_spacing_hours: number; empty_probe_threshold: number; max_cases_per_cycle: number; max_probes_per_cycle: number; max_advisor_escalations_per_cycle: number; advisor_concurrency: number; upload_max_kilobytes: number; upload_expiry_hours: number }',
])]
final class AutomationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->role?->isAtLeast(UserRole::Admin) === true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'automation' => ['required', 'array:enabled,reconciliation_interval_minutes,grace_hours,probe_spacing_hours,empty_probe_threshold,max_cases_per_cycle,max_probes_per_cycle,max_advisor_escalations_per_cycle,advisor_concurrency,upload_max_kilobytes,upload_expiry_hours'],
            'automation.enabled' => ['required', 'boolean'],
            'automation.reconciliation_interval_minutes' => ['required', 'integer', 'between:5,1440'],
            'automation.grace_hours' => ['required', 'array:anime,tv,movie'],
            'automation.grace_hours.anime' => ['required', 'integer', 'between:1,8760'],
            'automation.grace_hours.tv' => ['required', 'integer', 'between:1,8760'],
            'automation.grace_hours.movie' => ['required', 'integer', 'between:1,8760'],
            'automation.probe_spacing_hours' => ['required', 'integer', 'between:1,720'],
            'automation.empty_probe_threshold' => ['required', 'integer', 'between:2,10'],
            'automation.max_cases_per_cycle' => ['required', 'integer', 'between:1,1000'],
            'automation.max_probes_per_cycle' => ['required', 'integer', 'between:1,100'],
            'automation.max_advisor_escalations_per_cycle' => ['required', 'integer', 'between:0,25'],
            'automation.advisor_concurrency' => ['required', 'integer', 'between:1,5'],
            'automation.upload_max_kilobytes' => ['required', 'integer', 'between:64,10240'],
            'automation.upload_expiry_hours' => ['required', 'integer', 'between:1,168'],
        ];
    }
}
