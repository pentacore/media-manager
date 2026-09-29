<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\MediaActionValidationRules;
use App\Enums\ServiceType;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReleaseSearchRequest extends FormRequest
{
    use MediaActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->mediaActionTargetRules(),
            'item_id' => ['required', 'integer', 'min:1'],
            'season_number' => ['nullable', 'integer', 'min:0'],
            'episode_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * Sonarr's release endpoint takes seriesId+seasonNumber or episodeId.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->input('service') === ServiceType::Sonarr->value && $this->input('season_number') === null && $this->input('episode_id') === null) {
                $validator->errors()->add('season_number', 'Choose a season or an episode to search.');
            }
        }];
    }
}
