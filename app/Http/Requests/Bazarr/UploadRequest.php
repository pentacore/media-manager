<?php

declare(strict_types=1);

namespace App\Http\Requests\Bazarr;

use finfo;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Override;

final class UploadRequest extends FormRequest
{
    private const array ALLOWED_MIME_TYPES = [
        'application/x-ass',
        'application/x-srt',
        'application/x-ssa',
        'application/x-subrip',
        'application/octet-stream',
        'text/plain',
        'text/vtt',
        'text/x-ass',
        'text/x-ssa',
        'text/x-subrip',
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
            'connection' => ['required', 'integer', 'min:1'],
            'media_type' => ['required', 'in:episode,movie'],
            'media_id' => ['required', 'integer', 'min:1'],
            'target_fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'language' => ['required', 'string', 'regex:/^[a-z]{2,3}(?:-[a-z0-9]+)?$/D'],
            'forced' => ['required', 'boolean'],
            'hearing_impaired' => ['required', 'boolean'],
            'subtitle_file' => [
                'required',
                'file',
                'max:5120',
                'extensions:srt,ass,ssa,vtt,sub',
                $this->validSubtitleContent(...),
            ],
            'path' => ['prohibited'],
            'url' => ['prohibited'],
        ];
    }

    private function validSubtitleContent(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            return;
        }

        $detectedMimeType = new finfo(FILEINFO_MIME_TYPE)->file($value->getRealPath());

        if (! is_string($detectedMimeType) || ! in_array($detectedMimeType, self::ALLOWED_MIME_TYPES, true)) {
            $fail('The subtitle file must have a supported text subtitle MIME type.');

            return;
        }

        $contents = file_get_contents($value->getRealPath());

        if (! is_string($contents)
            || str_contains($contents, "\0")
            || ! mb_check_encoding($contents, 'UTF-8')
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $contents) === 1) {
            $fail('The subtitle file must contain valid UTF-8 text.');
        }
    }

    #[Override]
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('language'))) {
            $this->merge(['language' => strtolower(trim($this->string('language')->toString()))]);
        }
    }
}
