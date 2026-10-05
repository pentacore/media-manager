<?php

declare(strict_types=1);

namespace App\Http\Requests\Media;

use App\Concerns\PinnedConnectionValidationRules;
use Illuminate\Foundation\Http\FormRequest;

class DestroyArrMediaRequest extends FormRequest
{
    use PinnedConnectionValidationRules;

    /**
     * The connection the title page was rendered from. Media ids overlap
     * between instances, so the delete is pinned to it and never falls back
     * to "the active one".
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->pinnedConnectionRules(),
            'delete_files' => ['sometimes', 'boolean'],
        ];
    }
}
