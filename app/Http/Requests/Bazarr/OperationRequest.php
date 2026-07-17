<?php

declare(strict_types=1);

namespace App\Http\Requests\Bazarr;

use Override;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class OperationRequest extends FormRequest
{
    private const array OPERATIONS = [
        'download_best',
        'download_exact',
        'delete_subtitle',
        'sync_subtitle',
        'translate_subtitle',
        'modify_subtitle',
        'scan_media',
    ];

    public function authorize(): bool
    {
        return $this->user()?->isMember() === true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'operation' => ['required', 'string', Rule::in(self::OPERATIONS)],
            'connection' => ['required', 'integer', 'min:1'],
            'media_type' => ['required', 'in:episode,movie'],
            'media_id' => ['required', 'integer', 'min:1'],
            'target_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'language' => ['required_if:operation,download_best', 'nullable', 'string', 'regex:/^[a-z]{2,3}(?:-[a-z0-9]+)?$/D'],
            'forced' => ['required_if:operation,download_best', 'nullable', 'boolean'],
            'hearing_impaired' => ['required_if:operation,download_best', 'nullable', 'boolean'],
            'candidate_fingerprint' => ['required_if:operation,download_exact', 'nullable', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'subtitle_fingerprint' => [
                'required_if:operation,delete_subtitle,sync_subtitle,translate_subtitle,modify_subtitle',
                'nullable',
                'string',
                'regex:/^[a-f0-9]{64}$/D',
            ],
            'tool_action' => ['required_if:operation,modify_subtitle', 'nullable', 'string', Rule::in([
                'remove_HI',
                'remove_tags',
                'OCR_fixes',
                'common',
                'fix_uppercase',
                'reverse_rtl',
            ])],
            'media_action' => ['required_if:operation,scan_media', 'nullable', 'string', Rule::in([
                'scan-disk',
                'search-missing',
                'search-wanted',
                'sync',
            ])],
            'options' => ['nullable', 'array:reference,max_offset_seconds,no_fix_framerate,gss,original_format'],
            'options.reference' => ['nullable', 'string', 'max:100'],
            'options.max_offset_seconds' => ['nullable', 'numeric', 'between:0,600'],
            'options.no_fix_framerate' => ['nullable', 'boolean'],
            'options.gss' => ['nullable', 'boolean'],
            'options.original_format' => ['nullable', 'boolean'],
            'path' => ['prohibited'],
            'url' => ['prohibited'],
            'subtitle' => ['prohibited'],
            'provider' => ['prohibited'],
            'provider_url' => ['prohibited'],
        ];
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('language'))) {
            $this->merge(['language' => strtolower(trim($this->string('language')->toString()))]);
        }
    }
}
