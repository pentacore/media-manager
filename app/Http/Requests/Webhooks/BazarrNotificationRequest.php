<?php

declare(strict_types=1);

namespace App\Http\Requests\Webhooks;

use Illuminate\Foundation\Http\FormRequest;
use Override;

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
     * Bazarr notifies through Apprise, whose Custom JSON service posts
     * `version`, `title`, `message` and `type` — there is no `eventType` and no
     * structured arr identifiers. Templated setups may still add the richer
     * fields, so both shapes are accepted and a body carrying neither a
     * message nor an event type is rejected.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'eventType' => ['nullable', 'string', 'max:100', 'required_without:message'],
            'message' => ['nullable', 'string', 'required_without:eventType'],
            'title' => ['nullable', 'string', 'max:200'],
            'type' => ['nullable', 'string', 'max:100'],
            'version' => ['nullable', 'string', 'max:50'],
            'sonarrSeriesId' => ['nullable', 'integer', 'min:1'],
            'sonarrEpisodeId' => ['nullable', 'integer', 'min:1'],
            'radarrId' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
