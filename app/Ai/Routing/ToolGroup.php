<?php

declare(strict_types=1);

namespace App\Ai\Routing;

use App\Ai\Decision\InspectStuckImportTool;
use App\Ai\Tools\Arr\AddMediaTool;
use App\Ai\Tools\Arr\DeleteMediaTool;
use App\Ai\Tools\Arr\FindReplacementCandidatesTool;
use App\Ai\Tools\Arr\GetDownloadHistoryTool;
use App\Ai\Tools\Arr\GetDownloadQueueTool;
use App\Ai\Tools\Arr\GetMediaTool;
use App\Ai\Tools\Arr\InspectMediaFileTool;
use App\Ai\Tools\Arr\MonitorMediaTool;
use App\Ai\Tools\Arr\RemoveStuckDownloadChatTool;
use App\Ai\Tools\Arr\ReplaceMediaFileTool;
use App\Ai\Tools\Arr\ResolveManualImportChatTool;
use App\Ai\Tools\Arr\SearchMediaTool;
use App\Ai\Tools\Arr\SetMediaQualityProfileTool;
use App\Ai\Tools\Bazarr\InspectSubtitleTool;
use App\Ai\Tools\Bazarr\RequestSubtitleOperationTool;
use App\Ai\Tools\Bazarr\SearchSubtitlesTool;
use App\Ai\Tools\Emby\LibraryScanTool;
use App\Ai\Tools\Emby\MarkAsUnwatchedTool;
use App\Ai\Tools\Emby\MarkAsWatchedTool;
use App\Ai\Tools\Emby\NowPlayingTool;
use App\Ai\Tools\Emby\WatchHistoryTool;
use App\Ai\Tools\Prowlarr\ListIndexersTool;
use App\Ai\Tools\Prowlarr\SearchIndexersTool;
use App\Ai\Tools\Seerr\ApproveRequestTool;
use App\Ai\Tools\Seerr\CleanupRequestTool;
use App\Ai\Tools\Seerr\DeclineRequestTool;
use App\Ai\Tools\Seerr\DiscoverMoviesTool;
use App\Ai\Tools\Seerr\DiscoverTvTool;
use App\Ai\Tools\Seerr\GetTitleTool;
use App\Ai\Tools\Seerr\ListPendingRequestsTool;
use App\Ai\Tools\Seerr\SearchCatalogTool;
use App\Ai\Tools\System\GetServiceStatusTool;
use App\Ai\Tools\System\QueryActivityTool;
use App\Ai\Tools\System\SemanticLibrarySearchTool;
use App\Ai\Tools\Tmdb\TmdbGetCreditsTool;
use App\Ai\Tools\Tmdb\TmdbGetSimilarTool;
use App\Ai\Tools\Tmdb\TmdbGetTitleTool;
use App\Ai\Tools\Trakt\TraktGetListTool;
use App\Ai\Tools\Trakt\TraktGetPopularTool;
use App\Ai\Tools\Trakt\TraktGetTrendingTool;
use App\Ai\Tools\Workflow\ProposeWorkflowTool;
use App\Concerns\EnumUtils;

/**
 * A routable slice of MediaAgent's toolset. ChatToolRouter asks one yes/no
 * question per group and loads only the groups a chat message needs, on top
 * of the always-loaded core tools.
 */
enum ToolGroup: string
{
    use EnumUtils;

    case Downloads = 'downloads';
    case LibraryChanges = 'library_changes';
    case Requests = 'requests';
    case Discovery = 'discovery';
    case Playback = 'playback';
    case SubtitlesReplacement = 'subtitles_replacement';
    case Indexers = 'indexers';

    public function label(): string
    {
        return match ($this) {
            self::Downloads => 'Downloads',
            self::LibraryChanges => 'Library changes',
            self::Requests => 'Requests',
            self::Discovery => 'Discovery',
            self::Playback => 'Playback',
            self::SubtitlesReplacement => 'Subtitles & replacement',
            self::Indexers => 'Indexers',
        };
    }

    /**
     * The yes/no question the classifier answers for this group.
     */
    public function question(): string
    {
        return match ($this) {
            self::Downloads => 'Is the user asking about downloads, the download queue, download history, or a stuck or failed import?',
            self::LibraryChanges => 'Does the user want to add, delete, monitor, change the quality profile of, or rescan something in the media library?',
            self::Requests => 'Is the user asking about media requests — pending, approving, declining or cleaning them up?',
            self::Discovery => 'Does the user want recommendations, trending or popular titles, similar titles, cast/crew, or to find something new to watch?',
            self::Playback => 'Is the user asking what is playing, their watch history, or to mark something watched or unwatched?',
            self::SubtitlesReplacement => "Is the user asking about subtitles, a media file's tracks, or replacing a file with a different release?",
            self::Indexers => 'Is the user asking about indexers or wants to search indexers directly for releases?',
        };
    }

    /**
     * @return list<class-string>
     */
    public function toolClasses(): array
    {
        return match ($this) {
            self::Downloads => [
                GetDownloadQueueTool::class,
                GetDownloadHistoryTool::class,
                InspectStuckImportTool::class,
                ResolveManualImportChatTool::class,
                RemoveStuckDownloadChatTool::class,
            ],
            self::LibraryChanges => [
                AddMediaTool::class,
                MonitorMediaTool::class,
                SetMediaQualityProfileTool::class,
                DeleteMediaTool::class,
                LibraryScanTool::class,
            ],
            self::Requests => [
                SearchCatalogTool::class,
                GetTitleTool::class,
                ListPendingRequestsTool::class,
                ApproveRequestTool::class,
                DeclineRequestTool::class,
                CleanupRequestTool::class,
            ],
            self::Discovery => [
                SearchCatalogTool::class,
                GetTitleTool::class,
                DiscoverMoviesTool::class,
                DiscoverTvTool::class,
                TmdbGetTitleTool::class,
                TmdbGetSimilarTool::class,
                TmdbGetCreditsTool::class,
                TraktGetTrendingTool::class,
                TraktGetPopularTool::class,
                TraktGetListTool::class,
            ],
            self::Playback => [
                NowPlayingTool::class,
                WatchHistoryTool::class,
                MarkAsWatchedTool::class,
                MarkAsUnwatchedTool::class,
            ],
            self::SubtitlesReplacement => [
                InspectMediaFileTool::class,
                FindReplacementCandidatesTool::class,
                ReplaceMediaFileTool::class,
                InspectSubtitleTool::class,
                SearchSubtitlesTool::class,
                RequestSubtitleOperationTool::class,
            ],
            self::Indexers => [
                SearchIndexersTool::class,
                ListIndexersTool::class,
            ],
        };
    }

    /**
     * Tools every routed turn keeps, whatever the classifier said.
     *
     * @return list<class-string>
     */
    public static function core(): array
    {
        return [
            GetServiceStatusTool::class,
            QueryActivityTool::class,
            SemanticLibrarySearchTool::class,
            SearchMediaTool::class,
            GetMediaTool::class,
            ProposeWorkflowTool::class,
        ];
    }

    /**
     * The groups containing the tool the model called by this name.
     *
     * @return list<self>
     */
    public static function forToolName(string $name): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $toolGroup): bool => in_array($name, array_map(class_basename(...), $toolGroup->toolClasses()), true),
        ));
    }
}
