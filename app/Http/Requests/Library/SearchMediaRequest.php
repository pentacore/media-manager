<?php

declare(strict_types=1);

namespace App\Http\Requests\Library;

use App\Concerns\MediaActionValidationRules;
use App\Enums\MediaSearchCommand;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class SearchMediaRequest extends FormRequest
{
    use MediaActionValidationRules;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->mediaActionTargetRules(),
            'command' => ['required', MediaSearchCommand::validationRule()],
            'series_id' => ['nullable', 'integer', 'min:1', 'required_if:command,series_search,season_search,episode_search'],
            'season_number' => ['nullable', 'integer', 'min:0', 'required_if:command,season_search'],
            'episode_ids' => ['nullable', 'array', 'max:500', 'required_if:command,episode_search'],
            'episode_ids.*' => ['integer', 'min:1'],
            'movie_ids' => ['nullable', 'array', 'max:500', 'required_if:command,movies_search'],
            'movie_ids.*' => ['integer', 'min:1'],
        ];
    }

    /**
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $command = MediaSearchCommand::tryFrom((string) $this->input('command'));

            if ($command instanceof MediaSearchCommand && $command->service()->value !== $this->input('service')) {
                $validator->errors()->add('command', 'That search does not belong to this service.');
            }
        }];
    }
}
