<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\MediaActionValidationRules;
use App\Enums\ServiceType;
use Illuminate\Foundation\Http\FormRequest;

class MonitorEpisodesRequest extends FormRequest
{
    use MediaActionValidationRules;

    /**
     * Episodes exist only in Sonarr — the service is implied, not submitted.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['service' => ServiceType::Sonarr->value]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->mediaActionTargetRules(),
            'series_id' => ['required', 'integer', 'min:1'],
            'season_number' => ['nullable', 'integer', 'min:0'],
            'episode_ids' => ['required', 'array', 'min:1', 'max:500'],
            'episode_ids.*' => ['integer', 'min:1', 'distinct'],
            'monitored' => ['required', 'boolean'],
        ];
    }
}
