<?php

declare(strict_types=1);

namespace App\Services\Bazarr;

use App\Services\Actions\ActionDescription;

/**
 * Approval-card wording for Bazarr subtitle operations. The Subtitle Center
 * controllers, the chat tool and the automatic download creator queue the same
 * operations; their title, effect sentence and facts live here, and each caller
 * adds its "why". The item is always resolved server-side, so the description
 * is verified.
 */
final readonly class SubtitleOperationDescriber
{
    /**
     * @param  array<string, mixed>  $item
     * @param  array<string, mixed>  $operationPayload
     */
    public function describe(string $operation, array $item, array $operationPayload): ActionDescription
    {
        $mediaTitle = is_string($item['title'] ?? null) && $item['title'] !== '' ? $item['title'] : 'media';
        $actionDescription = new ActionDescription(
            title: $this->title($operation, $mediaTitle),
            description: $this->effect($operation),
            details: [['label' => 'Media', 'value' => $mediaTitle]],
        );

        return match ($operation) {
            'download_best', 'upload_subtitle' => $actionDescription
                ->withDetail('Language', $this->string($operationPayload['language'] ?? null))
                ->withDetail('Forced', ($operationPayload['forced'] ?? false) === true)
                ->withDetail('Hearing impaired', ($operationPayload['hearing_impaired'] ?? false) === true),
            'delete_subtitle', 'sync_subtitle', 'translate_subtitle', 'modify_subtitle' => $actionDescription
                ->withDetail('Subtitle', $this->trackName($item, $operationPayload['subtitle_fingerprint'] ?? null))
                ->withDetail('Tool', $this->string($operationPayload['tool_action'] ?? null)),
            'scan_media' => $actionDescription->withDetail('Scan', $this->string($operationPayload['media_action'] ?? null)),
            default => $actionDescription,
        };
    }

    private function title(string $operation, string $mediaTitle): string
    {
        return match ($operation) {
            'download_best' => sprintf('Download the best subtitle for %s', $mediaTitle),
            'download_exact' => sprintf('Download a selected subtitle for %s', $mediaTitle),
            'delete_subtitle' => sprintf('Delete a subtitle for %s', $mediaTitle),
            'sync_subtitle' => sprintf('Synchronize a subtitle for %s', $mediaTitle),
            'translate_subtitle' => sprintf('Translate a subtitle for %s', $mediaTitle),
            'modify_subtitle' => sprintf('Modify a subtitle for %s', $mediaTitle),
            'scan_media' => sprintf('Scan subtitles for %s', $mediaTitle),
            'upload_subtitle' => sprintf('Upload a subtitle for %s', $mediaTitle),
            default => sprintf('Run a subtitle operation for %s', $mediaTitle),
        };
    }

    private function effect(string $operation): string
    {
        return match ($operation) {
            'download_best' => 'Bazarr will search its providers and download the best-scoring subtitle.',
            'download_exact' => 'Bazarr will download the subtitle that was selected.',
            'delete_subtitle' => 'Bazarr will delete this subtitle file.',
            'sync_subtitle' => 'Bazarr will re-time this subtitle to match the audio.',
            'translate_subtitle' => 'Bazarr will translate this subtitle into another language.',
            'modify_subtitle' => 'Bazarr will apply a subtitle tool to this subtitle.',
            'scan_media' => 'Bazarr will rescan this media for subtitles.',
            'upload_subtitle' => 'Bazarr will add the uploaded subtitle file to this media.',
            default => 'Bazarr will run this subtitle operation.',
        };
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function trackName(array $item, mixed $fingerprint): ?string
    {
        foreach (is_array($item['subtitle_tracks'] ?? null) ? $item['subtitle_tracks'] : [] as $track) {
            if (is_array($track) && ($track['fingerprint'] ?? null) === $fingerprint && is_string($track['display_name'] ?? null)) {
                return $track['display_name'];
            }
        }

        return null;
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
