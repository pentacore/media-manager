<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Concerns\ChatTemplateValidationRules;
use App\Models\ChatTemplate;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateChatTemplateRequest extends FormRequest
{
    use ChatTemplateValidationRules;

    /**
     * Ownership is checked before validation so a foreign template answers
     * 404 rather than leaking its existence through 422 errors.
     */
    public function authorize(): bool
    {
        $user = $this->user();

        abort_unless($user instanceof User && $this->chatTemplate()->isOwnedBy($user), 404);

        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->chatTemplateRules($this->chatTemplate());
    }

    private function chatTemplate(): ChatTemplate
    {
        $chatTemplate = $this->route('chatTemplate');

        abort_unless($chatTemplate instanceof ChatTemplate, 404);

        return $chatTemplate;
    }
}
