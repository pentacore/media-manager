<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Services\Chat\ChatTemplateRenderer;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "Save as template" on a chat message. The message travels in the POST body,
 * not the query string, so a long one never hits a proxy's URI length limit.
 */
class DraftChatTemplateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', sprintf('max:%d', ChatTemplateRenderer::MAX_LENGTH)],
        ];
    }
}
