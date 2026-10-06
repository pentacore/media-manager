<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\MediaActionValidationRules;
use App\Enums\ServiceType;
use Illuminate\Foundation\Http\FormRequest;

class MonitorSeasonRequest extends FormRequest
{
    use MediaActionValidationRules;

    /**
     * Seasons exist only in Sonarr — the service is implied, not submitted.
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
            'season_number' => ['required', 'integer', 'min:0'],
        ];
    }
}
