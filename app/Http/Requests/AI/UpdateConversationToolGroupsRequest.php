<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Ai\Routing\ToolGroup;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConversationToolGroupsRequest extends FormRequest
{
    /**
     * Route middleware (`role:admin`, `ai.enabled`) authorises the change.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'groups' => ['present', 'array', 'max:'.count(ToolGroup::cases())],
            'groups.*' => ['string', 'distinct', Rule::enum(ToolGroup::class)],
        ];
    }
}
