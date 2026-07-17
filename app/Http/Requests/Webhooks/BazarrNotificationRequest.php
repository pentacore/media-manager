<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Override;
use Illuminate\Foundation\Http\FormRequest;

final class BazarrNotificationRequest extends FormRequest
{
    private const int MAX_BODY_BYTES = 65_536;

    public function authorize(): bool
    {
        return true;
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        abort_if(strlen($this->getContent()) > self::MAX_BODY_BYTES, 413, 'Notification payload is too large.');
        abort_unless($this->isJson(), 415, 'Notification payload must be JSON.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'eventType' => ['required', 'string', 'max:100'],
            'sonarrSeriesId' => ['nullable', 'integer', 'min:1'],
            'sonarrEpisodeId' => ['nullable', 'integer', 'min:1'],
            'radarrId' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
