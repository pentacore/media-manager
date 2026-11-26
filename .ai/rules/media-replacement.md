---
paths:
  - 'app/Services/MediaReplacement/**'
  - 'app/Services/Bazarr/**'
  - 'app/Ai/SubtitleAdvisor/**'
  - 'app/Ai/Tools/Bazarr/**'
  - 'app/Ai/Tools/Arr/*Subtitles*Tool.php'
  - 'app/Ai/Tools/Arr/FindReplacementCandidatesTool.php'
  - 'app/Cache/Services/BazarrCache.php'
  - 'app/Concerns/Bazarr*ValidationRules.php'
  - 'app/Concerns/ReplacementTargetValidationRules.php'
  - 'app/Console/Commands/*Subtitle*.php'
  - 'app/Console/Commands/*Replacement*.php'
  - 'app/Enums/Bazarr*.php'
  - 'app/Enums/MediaReplacement*.php'
  - 'app/Enums/Subtitle*.php'
  - 'app/Events/MediaReplacement*.php'
  - 'app/Events/Subtitle*.php'
  - 'app/Http/Controllers/Bazarr/**'
  - 'app/Http/Controllers/Admin/MediaReplacement*.php'
  - 'app/Http/Controllers/Media/MediaReplacementController.php'
  - 'app/Http/Controllers/AI/SubtitleAuditBatchController.php'
  - 'app/Http/Controllers/Webhooks/BazarrNotificationController.php'
  - 'app/Http/Requests/Bazarr/**'
  - 'app/Http/Requests/Admin/UpdateMediaReplacementSettingsRequest.php'
  - 'app/Http/Requests/Media/*Replacement*.php'
  - 'app/Http/Requests/Webhooks/BazarrNotificationRequest.php'
  - 'app/Http/Resources/Bazarr/**'
  - 'app/Jobs/*Subtitle*.php'
  - 'app/Jobs/ReconcileBazarrConnection.php'
  - 'app/Jobs/Middleware/*Subtitle*.php'
  - 'app/Listeners/UpdateSubtitleCaseFrom*.php'
  - 'app/Models/BazarrServiceLink.php'
  - 'app/Models/MediaReplacementAttempt.php'
  - 'app/Models/Subtitle*.php'
  - 'app/Notifications/MediaReplacement*.php'
  - 'app/Notifications/SubtitleCase*.php'
  - 'app/Settings/BazarrAutomationSettings.php'
  - 'app/Settings/MediaReplacementSettings.php'
  - 'database/factories/Bazarr*.php'
  - 'database/factories/MediaReplacement*.php'
  - 'database/factories/Subtitle*.php'
  - 'resources/js/components/bazarr/**'
  - 'resources/js/components/media-replacement/**'
  - 'resources/js/lib/subtitleAudit.ts'
  - 'resources/js/pages/Bazarr/**'
  - 'resources/js/pages/Admin/MediaReplacement/**'
  - 'routes/bazarr.php'
  - 'tests/Feature/Bazarr/**'
  - 'tests/Feature/Services/Bazarr/**'
  - 'tests/Feature/Services/MediaReplacement/**'
  - 'tests/Unit/Services/Bazarr/**'
  - 'tests/Unit/Services/MediaReplacement/**'
  - 'tests/**/*Bazarr*Test.php'
  - 'tests/**/*MediaReplacement*Test.php'
  - 'tests/**/*Subtitle*Test.php'
---

# Media replacement and subtitles

Media replacement (attempts, candidates, the Replace file flow) and subtitles (Bazarr, subtitle cases and their reconciliation, the subtitle audit, the Media Advisor), in every layer. Replacement and Bazarr action descriptions follow `actions.md`.

## The Media Advisor decides in code
`RunSubtitleAdvisor` decides subtitle replacements with `SubtitleAdvisorDecider`: it queues the projection's unique `automatic_candidate` through `QueueAutomaticReplacementTool` (its re-validation and BaseTool's gated queueing stay the only queueing path), otherwise it routes the case to review with a templated summary. No model runs there (only the optional triage classifier), so no usage row is written. Do not wrap a fixed rule in an agent again.

## The subtitle discovery memo lives for one injected instance
`App\Services\Bazarr\SubtitleCaseCandidates` memoizes one reconciliation cycle's mapped-library scan in `$discoveryFeeds`, so it is `final` but not `readonly` and is never bound in a provider: `ReconcileBazarrConnection::handle()` method-injects it, which makes one instance one cycle. Never bind it `singleton()` (a long-lived worker would serve every later cycle the first cycle's catalog) or `scoped()` (unbound already gives one instance per injection, which is today's behaviour — `scoped()` adds nothing and invites a future per-request-but-cross-cycle leak), and do not cache its result across instances. The other inventory collaborators (`SubtitleLibraryReader`, `SubtitleInspector`, `SubtitleItemMapper`, `SubtitleInventoryConnections`) are stateless `final readonly` classes; add new subtitle inventory reads to the one that owns the concern rather than reviving a catch-all service.
