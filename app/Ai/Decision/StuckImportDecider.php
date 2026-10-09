<?php

declare(strict_types=1);

namespace App\Ai\Decision;

use App\Ai\Classification\Classifier;
use App\Enums\StuckImportChoice;
use App\Models\ServiceConnection;
use App\Services\Arr\StuckImportInspector;
use App\Settings\AiSettings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Responses\Data\Answer;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Throwable;

/**
 * Decides a stuck import from the deterministic inspection with one
 * classification call: import, remove or leave for a human, plus whether a
 * removal should blocklist the release and search for a replacement.
 */
final readonly class StuckImportDecider
{
    /** Probability a removal flag needs before it is set. */
    public const float FLAG_THRESHOLD = 0.8;

    /** Candidate files sent to the classifier, at most. */
    private const int MAX_FILES = 20;

    /**
     * Each of a file's upstream rejection reasons, truncated to this many
     * characters, so a release with pathological rejection text can't blow
     * out the classification payload even while MAX_FILES caps the file
     * count.
     */
    private const int MAX_REJECTION_CHARS = 300;

    private const string CHOICE_QUESTION = "A Sonarr/Radarr download is stuck waiting for manual import. Given each candidate file's mapping and the upstream rejection reasons, what should happen to it?";

    private const string BLOCKLIST_QUESTION = 'Is the release itself bad (corrupt, fake or wrong content), so it should be blocklisted and never grabbed again?';

    private const string SEARCH_QUESTION = 'Is the content still wanted, so a replacement release should be searched for after this one is removed?';

    public function __construct(
        private StuckImportInspector $stuckImportInspector,
        private Classifier $classifier,
        private AiSettings $aiSettings,
    ) {}

    public function decide(ServiceConnection $serviceConnection, string $service, string $downloadId): ?StuckImportDecision
    {
        try {
            $inspection = $this->stuckImportInspector->inspect($serviceConnection, $service, $downloadId);
        } catch (Throwable $throwable) {
            Log::warning('StuckImportDecider: inspection failed', [
                'service' => $service,
                'download_id' => $downloadId,
                'exception' => $throwable::class,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        if ($inspection['total'] === 0) {
            return null;
        }

        $answers = $this->classifier->classify(self::class, [
            ...$inspection,
            'files' => $this->cappedFiles($inspection['files']),
        ], [
            'choice' => new Choice(self::CHOICE_QUESTION, StuckImportChoice::options()),
            'blocklist' => new Boolean(self::BLOCKLIST_QUESTION),
            'search_replacement' => new Boolean(self::SEARCH_QUESTION),
        ]);

        if ($answers === null) {
            return null;
        }

        $choiceAnswer = $answers['choice'] ?? null;
        $choice = $choiceAnswer instanceof ChoiceAnswer ? StuckImportChoice::tryFrom($choiceAnswer->choice) : null;

        if (! $choiceAnswer instanceof ChoiceAnswer || ! $choice instanceof StuckImportChoice) {
            return null;
        }

        $probability = $choiceAnswer->probabilityOf($choiceAnswer->choice);
        $blocklistProbability = $this->booleanProbability($answers['blocklist'] ?? null);
        $searchReplacementProbability = $this->booleanProbability($answers['search_replacement'] ?? null);
        $isRemoval = $choice === StuckImportChoice::Remove;

        return new StuckImportDecision(
            choice: $choice,
            probability: $probability,
            isConfident: $probability >= $this->aiSettings->stuckImportThreshold(),
            blocklist: $isRemoval && ($blocklistProbability ?? 0.0) >= self::FLAG_THRESHOLD,
            searchReplacement: $isRemoval && ($searchReplacementProbability ?? 0.0) >= self::FLAG_THRESHOLD,
            blocklistProbability: $blocklistProbability,
            searchReplacementProbability: $searchReplacementProbability,
            inspection: $inspection,
        );
    }

    private function booleanProbability(?Answer $answer): ?float
    {
        return $answer instanceof BooleanAnswer ? $answer->probability : null;
    }

    /**
     * At most MAX_FILES candidate files, each with its rejection text capped
     * to MAX_REJECTION_CHARS.
     *
     * @param  array<int, array<string, mixed>>  $files
     * @return array<int, array<string, mixed>>
     */
    private function cappedFiles(array $files): array
    {
        return array_map(
            fn (array $file): array => [
                ...$file,
                'rejections' => array_map(
                    static fn (string $rejection): string => Str::limit($rejection, self::MAX_REJECTION_CHARS, ''),
                    is_array($file['rejections'] ?? null) ? $file['rejections'] : [],
                ),
            ],
            array_slice($files, 0, self::MAX_FILES),
        );
    }
}
