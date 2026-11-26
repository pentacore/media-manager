# Glossary

The words this project uses for its domain, and the words to avoid. Use these words in code, UI copy, rules, commit messages and pull requests. The "Words to avoid" list is a draft for the owner to prune.

## Terms

| Term | Meaning | Code name |
| --- | --- | --- |
| Service | An external app Media Manager talks to: Sonarr, Radarr, Whisparr, Prowlarr, SABnzbd, Bazarr, Emby, Seerr. | `ServiceType` |
| Connection | One configured instance of a service (URL, API key, options). A service type can have several. | `ServiceConnection` |
| Library | The series or movies a Sonarr, Radarr or Whisparr connection manages. | `app/Services/Arr`, `IndexedSeries`, `IndexedMovie` |
| Series | A TV series in Sonarr. | `SonarrClient`, `IndexedSeries` |
| Movie | A movie in Radarr. | `RadarrClient`, `IndexedMovie` |
| Wanted | Library items that are missing or below their quality cutoff. | `LibraryWanted` |
| Grab | Sending a release to a download client. | `prowlarr.grab`, `CompetingGrabSweeper` |
| Grab queue | The *arr download queue and history page. | `LibraryActivityController`, `pages/Library/Activity.vue` |
| Downloads | The SABnzbd queue and history page. | `Sabnzbd\QueueController` |
| Seerr request | A user's request for a title in Seerr. | `SeerrClient`, `RequestController`, `MyRequestController` |
| Webhook event | An inbound webhook from a service, stored and then handled on the queue. | `WebhookEvent`, `{Service}WebhookHandler` |
| Action request | A library-changing write that waits for approval, then runs. | `ActionRequest`, `ActionRequestStatus` |
| Action Queue | The page that lists action requests for review. | `pages/Actions/Index.vue` |
| Action type | The kind of write an action request performs, such as `sonarr_delete_series`. | `ActionTypeConfig::$type` |
| Approval rule | Per action type: whether it is enabled, needs approval and gets a second opinion. | `ActionTypeConfig` |
| Action description | The server-verified title and details of an action request. | `ActionDescription`, `ActionDescriber` |
| Executor | The class that carries out an approved action request against a service. | `ActionExecutor`, `{Service}Actions` |
| Status version | A sequence number that orders an action request's status changes. | `action_requests.status_version` |
| Activity log | The record of what happened, shown per user; audit rows are admin-only. | `ActivityLog`, `ActivityLogCategory` |
| Audit row | An admin-only activity log row with secrets masked. | `AuditLogger` |
| Media replacement | Replacing a bad media file with a better release. | `MediaReplacementAttempt`, `app/Services/MediaReplacement` |
| Replacement candidate | A release ranked as a possible replacement. | `ReplacementCandidateFinder`, `ReleaseCandidateRanker` |
| Subtitle case | One media file's subtitle problem, tracked until it is resolved. | `SubtitleCase`, `SubtitleCaseStatus` |
| Subtitle audit | A check of every episode of a series for subtitles, with one batch repair. | `SubtitleAuditBatch`, `SeriesSubtitleAuditor` |
| Media Advisor | The code that decides an escalated subtitle case: queue a replacement or send it to review. | `RunSubtitleAdvisor`, `SubtitleAdvisorDecider` |
| Stuck import | A finished download that the *arr could not import. | `StuckImportChoice`, `InspectStuckImportTool` |
| AI mode | Executive (the AI acts) or advisory (the AI only suggests). | `AiMode` |
| AI task | A workload with its own model choice: chat, title, decision and others. | `AiTask`, `AiTaskModel` |
| Decision Agent | The AI that decides what to do about one webhook event. | `DecisionAgent`, `AgentDecision` |
| Second opinion | An independent check of a proposed action before it is queued. | `SecondOpinionVerifier`, `DecisionVerifierAgent`, `SecondOpinionMode` |
| Classification | A cheap gate that sorts an input before a model runs. | `ClassificationGate`, `ClassificationOutcome` |
| Destructive tool | An AI tool that queues an action request instead of calling a service. | `Risk::Destructive`, `BaseTool` |
| Tool group | A set of chat tools pinned, seen or requested together. | `ToolGroup`, `ToolGroupCatalogue` |
| Chat template | A saved chat prompt with variables. | `ChatTemplate` |
| Benchmark | Replaying recorded cases against models to compare them. | `BenchmarkCase`, `BenchmarkRun`, `BenchmarkSchedule` |
| Free usage pool | A provider's free token or request allowance shared by several models. | `AiFreeUsagePool` |
| Price refresh | The job and agent that update the AI model price catalog. | `RefreshAiPricesJob`, `PriceFetcherAgent` |
| Queue lane | The job queue a job runs on. | `QueueLane` |
| Server | A host watched by the host agent, with its checks and incidents. | `Server`, `ServerCheck`, `ServerIncident` |
| Ability | One of five capabilities a role grants. | `Ability`, `Abilities` |
| Role | Admin, member or viewer. | `UserRole` |

## Words to avoid

| Avoid | Say instead | Why |
| --- | --- | --- |
| "subtitle advisor" | "Media Advisor" | The UI and messages say Media Advisor; the README says subtitle advisor. Classes keep `SubtitleAdvisor`. |
| "reject" in UI copy | "decline" | The Action Queue button says Decline, the status label says Rejected. The stored status stays `rejected`. |
| "escalation" | "needs review" | The status is `needs_review` and the notification says "needs review"; the page says "Subtitle escalations". |
| "verifier" on its own | "second opinion" (decision check), "price refresh" (pricing) | It names two unrelated agents: the decision verifier and the price verifier. |
| "price verifier", "price updater", "price fetcher" | "price refresh" | One feature has four names: `AiTask::PriceUpdater`, `PriceFetcherAgent`, "price verifier" in AI Settings, `RefreshAiPricesJob`. |
| "queue" on its own | "Action Queue", "Downloads" (SABnzbd), "grab queue" (*arr), "queue lane" (jobs) | Four different queues. |
| "library activity" | "grab queue" | The sidebar says Grab queue, the page title says Library activity. "Activity" is the activity log. |
| "Subtitles" for the Bazarr section | "Subtitle Center" | The sidebar says Subtitles, the page title says Subtitle Center. |
| "action type config" in prose | "approval rule" | The page and sidebar say Approval rules; the model is `ActionTypeConfig`. |
| "show" for a TV series | "series" | "Show" is also the detail page (`Show.vue`, a controller's `show()`). Say "detail page" for that. |
| "request" on its own | "Seerr request", "action request" or "form request" | Three kinds of request share the word. |
| "integration" | "service" (the product) or "connection" (a configured instance) | `integrations` in the shared props means "optional services with an active connection". |
