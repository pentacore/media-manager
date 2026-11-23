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
        $this->create($classificationGate, $subjectKey, $question, $probability, $classificationVerdict, $threshold, $predicted);
    }

    /**
     * Store a row whose outcome is already known, so no later resolve() has to
     * find it among the subject's other open rows.
     */
    public function recordResolved(
        ClassificationGate $classificationGate,
        string $subjectKey,
        string $question,
        ?float $probability,
        ClassificationVerdict $classificationVerdict,
        ?float $threshold,
        ?string $predicted,
        bool $positive,
        string $detail,
    ): void {
        $this->create($classificationGate, $subjectKey, $question, $probability, $classificationVerdict, $threshold, $predicted, [
            'outcome_positive' => $positive,
            'outcome_detail' => Str::limit($detail, 255, ''),
            'outcome_at' => now(),
        ]);
    }

    /**
     * The questions of the subject's open rows that carry one of the given
     * verdicts, so a caller can skip resolving a subject that recorded none.
     *
     * @param  list<ClassificationVerdict>  $verdicts
     * @return list<string>
     */
    public function openQuestions(ClassificationGate $classificationGate, string $subjectKey, array $verdicts): array
    {
        try {
            return array_values($this->openRows($classificationGate, $subjectKey, $verdicts, null)->pluck('question')->all());
        } catch (Throwable $throwable) {
            $this->logFailure('openQuestions', $classificationGate, $subjectKey, $throwable);

            return [];
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
     * actually happened. An optional $predicted narrows which open rows are
     * resolved, leaving the rest (e.g. a prediction with no checkable
     * mapping) open.
     *
     * @param  list<ClassificationVerdict>  $verdicts
     */
    public function resolveAgainst(ClassificationGate $classificationGate, string $subjectKey, string $actual, array $verdicts, ?string $predicted = null): void
    {
        try {
            $detail = sprintf('actual: %s', $actual);

            $this->openRows($classificationGate, $subjectKey, $verdicts, null, $predicted)
                ->where('predicted', $actual)
                ->update(['outcome_positive' => true, 'outcome_detail' => $detail, 'outcome_at' => now(), 'updated_at' => now()]);

            $this->openRows($classificationGate, $subjectKey, $verdicts, null, $predicted)
                ->where(fn (Builder $builder): Builder => $builder->whereNull('predicted')->orWhere('predicted', '!=', $actual))
                ->update(['outcome_positive' => false, 'outcome_detail' => $detail, 'outcome_at' => now(), 'updated_at' => now()]);
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
     * @param  array<string, mixed>  $outcome
     */
    private function create(
        ClassificationGate $classificationGate,
        string $subjectKey,
        string $question,
        ?float $probability,
        ClassificationVerdict $classificationVerdict,
        ?float $threshold,
        ?string $predicted,
        array $outcome = [],
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
                ...$outcome,
            ]);
        } catch (Throwable $throwable) {
            $this->logFailure('record', $classificationGate, $subjectKey, $throwable);
        }
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
