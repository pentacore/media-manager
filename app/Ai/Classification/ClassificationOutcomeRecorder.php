<?php

declare(strict_types=1);

namespace App\Ai\Classification;

use App\Enums\ClassificationGate;
use App\Enums\ClassificationVerdict;
use App\Models\ClassificationOutcome;
use App\Settings\AiSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Lottery;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores what each classification gate decided and, later, what actually
 * happened, so thresholds can be set from data. Never throws: a tracking
 * failure must not change the gate's behaviour.
 */
final readonly class ClassificationOutcomeRecorder
{
    public function __construct(private AiSettings $aiSettings) {}

    public function record(
        ClassificationGate $classificationGate,
        string $subjectKey,
        string $question,
        ?float $probability,
        ClassificationVerdict $classificationVerdict,
        ?float $threshold = null,
        ?string $predicted = null,
    ): void {
        try {
            ClassificationOutcome::query()->create([
                'gate' => $classificationGate,
                'subject_key' => Str::limit($subjectKey, 100, ''),
                'question' => Str::limit($question, 100, ''),
                'predicted' => $predicted === null ? null : Str::limit($predicted, 100, ''),
                'probability' => $probability,
                'threshold' => $threshold,
                'verdict' => $classificationVerdict,
            ]);
        } catch (Throwable $throwable) {
            $this->logFailure('record', $classificationGate, $subjectKey, $throwable);
        }
    }

    /**
     * Fill the outcome of the subject's open rows that carry one of the given
     * verdicts (and the given question and/or prediction, when passed).
     *
     * @param  list<ClassificationVerdict>  $verdicts
     */
    public function resolve(
        ClassificationGate $classificationGate,
        string $subjectKey,
        bool $positive,
        string $detail,
        array $verdicts,
        ?string $question = null,
        ?string $predicted = null,
    ): void {
        try {
            $this->openRows($classificationGate, $subjectKey, $verdicts, $question, $predicted)->update([
                'outcome_positive' => $positive,
                'outcome_detail' => Str::limit($detail, 255, ''),
                'outcome_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $throwable) {
            $this->logFailure('resolve', $classificationGate, $subjectKey, $throwable);
        }
    }

    /**
     * Fill each open row's outcome by comparing its prediction with what
     * actually happened.
     *
     * @param  list<ClassificationVerdict>  $verdicts
     */
    public function resolveAgainst(ClassificationGate $classificationGate, string $subjectKey, string $actual, array $verdicts): void
    {
        try {
            $this->openRows($classificationGate, $subjectKey, $verdicts, null)
                ->get()
                ->each(fn (ClassificationOutcome $classificationOutcome): bool => $classificationOutcome->update([
                    'outcome_positive' => $classificationOutcome->predicted === $actual,
                    'outcome_detail' => sprintf('actual: %s', $actual),
                    'outcome_at' => now(),
                ]));
        } catch (Throwable $throwable) {
            $this->logFailure('resolveAgainst', $classificationGate, $subjectKey, $throwable);
        }
    }

    /**
     * Whether a below-threshold decision should run anyway as an audit run.
     */
    public function shouldAudit(): bool
    {
        $rate = $this->aiSettings->classificationAuditSampleRate();

        return $rate > 0.0 && (bool) Lottery::odds($rate)->choose();
    }

    /**
     * @param  list<ClassificationVerdict>  $verdicts
     * @return Builder<ClassificationOutcome>
     */
    private function openRows(ClassificationGate $classificationGate, string $subjectKey, array $verdicts, ?string $question, ?string $predicted = null): Builder
    {
        return ClassificationOutcome::query()
            ->where('gate', $classificationGate)
            ->where('subject_key', $subjectKey)
            ->whereIn('verdict', $verdicts)
            ->whereNull('outcome_at')
            ->when($question !== null, fn (Builder $builder): Builder => $builder->where('question', $question))
            ->when($predicted !== null, fn (Builder $builder): Builder => $builder->where('predicted', $predicted));
    }

    private function logFailure(string $operation, ClassificationGate $classificationGate, string $subjectKey, Throwable $throwable): void
    {
        Log::warning('Classification outcome tracking failed.', [
            'operation' => $operation,
            'gate' => $classificationGate->value,
            'subject_key' => $subjectKey,
            'exception' => $throwable::class,
            'message' => $throwable->getMessage(),
        ]);
    }
}
