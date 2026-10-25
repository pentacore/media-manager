<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Concerns\BenchmarkCaseValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;
use Pentacore\Typefinder\Attributes\TypefinderOverrides;

/**
 * `input` and `scoring` are overridden because typefinder cannot render their
 * wildcard (`history.*`, `expected.*`) rules.
 */
#[TypefinderOverrides([
    'input' => "{ prompt?: string | null; history?: Array<{ role: 'user' | 'assistant'; content: string }> | null; mode?: 'executive' | 'advisory' | null; conversation?: 'context' | 'scripted' | null; event_key?: string | null; event?: { service: string; event_type: string; payload_json: string } | null; action_kind?: 'library_change' | 'seerr_request' | 'media_server' | 'other' | null; providers?: string[]; verifier?: { action_type: string; action_label: string; title: string; description: string; details?: Array<{ label: string; value: string }> | null; verified: boolean; payload_json: string; rationale?: string | null; source_service: string; event_type?: string | null; event_payload_json: string } | null }",
    'scoring' => "{ type: 'actions' | 'prices' | 'judge' | 'verdict'; expected?: Array<Record<string, unknown>> | null; expected_agrees?: boolean | null; tolerance_percent?: number | null; rubric_note?: string | null; pass_threshold?: number | null }",
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
