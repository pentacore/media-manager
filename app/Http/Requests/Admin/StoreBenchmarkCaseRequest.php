<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\BenchmarkCaseValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

// The attachment maps are keyed by slot name; the `.*` rules alone type them as lists.
#[TypefinderOverrides([
    'attachments' => 'Record<string, File[]> | null',
    'keep_attachment_ids' => 'Record<string, number[]> | null',
    'draft_attachment_ids' => 'Record<string, number[]> | null',
])]
class StoreBenchmarkCaseRequest extends FormRequest
{
    use BenchmarkCaseValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->benchmarkCaseRules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['scoring.expected_agrees.required_if' => 'Choose the expected verdict.'];
    }

    /**
     * The form posts the case body as a JSON `payload` string so booleans, numbers
     * and empty objects survive the multipart encoding; only the files stay real fields.
     */
    protected function prepareForValidation(): void
    {
        $this->nestFlatAttachmentFiles();

        $payload = $this->input('payload');

        if (! is_string($payload)) {
            return;
        }

        $decoded = json_decode($payload, true);

        if (is_array($decoded)) {
            $this->merge(Arr::except($decoded, ['attachments', 'payload']));
        }
    }

    /**
     * Some multipart readers (the browser-test HTTP server) keep a nested file name such
     * as `attachments[final][0]` as one flat key where PHP would nest it; nest it here.
     */
    private function nestFlatAttachmentFiles(): void
    {
        $nested = [];

        foreach ($this->files->all() as $name => $file) {
            if (preg_match('/^attachments\[([^\]]+)\]\[\d*\]$/', (string) $name, $matches) === 1) {
                $nested[$matches[1]][] = $file;
                $this->files->remove($name);
            }
        }

        if ($nested !== []) {
            $this->files->set('attachments', $nested);
        }
    }
}
