# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Services/Actions/**, app/Services/**/*Actions.php, app/Services/Library/LibraryActionRequester.php, app/Http/Controllers/Actions/**, app/Http/Controllers/Library/MediaActionController.php, app/Http/Controllers/Whisparr/WhisparrActionController.php, app/Http/Requests/Actions/**, app/Http/Requests/Library/Bulk*.php, app/Http/Requests/Whisparr/Bulk*.php, app/Http/Resources/Action*.php, app/Jobs/ExecuteActionRequest.php, app/Console/Commands/ReconcileStuckActionRequests.php, app/Events/ActionRequest*.php, app/Models/ActionRequest.php, app/Models/ActionTypeConfig.php, app/Observers/ActionRequest*.php, app/Enums/ActionRequestStatus.php, app/Enums/ActionQueueBulkAction.php, database/factories/Action*.php, database/seeders/ActionTypeConfigSeeder.php, resources/js/pages/Actions/**, resources/js/components/actions/**, resources/js/composables/useActionQueueLive.ts, tests/Feature/Actions/**, tests/Feature/Services/Actions/**, tests/**/ActionRequest*Test.php, tests/**/ActionQueue*Test.php, tests/**/ActionTypeConfig*Test.php, tests/Feature/Library/BulkLibraryActionTest.php, tests/Feature/Library/MediaActionControllerTest.php, tests/Feature/Whisparr/WhisparrActionControllerTest.php, tests/Feature/Services/Library/LibraryActionRequesterTest.php, tests/Feature/Console/ReconcileStuckActionRequestsCommandTest.php | .ai/rules/actions.md |
| app/Http/Controllers/Admin/AiSettingsController.php | .ai/rules/admin.md |
| app/Ai/Agents/MediaAgent.php, app/Ai/Chat*.php, app/Ai/Conversations/**, app/Ai/Routing/**, app/Ai/Tools/Arr/**, app/Ai/Tools/Bazarr/**, app/Ai/Tools/Emby/**, app/Ai/Tools/Prowlarr/**, app/Ai/Tools/Seerr/**, app/Ai/Tools/System/**, app/Ai/Tools/Tmdb/**, app/Ai/Tools/Trakt/**, app/Ai/Tools/Workflow/**, app/Concerns/Chat*ValidationRules.php, app/Console/Commands/Ai/PruneConversations.php, app/Enums/ChatTemplate*.php, app/Http/Controllers/AI/Chat*.php, app/Http/Controllers/AI/ConversationController.php, app/Http/Controllers/Admin/AiConversationController.php, app/Http/Requests/AI/*Chat*.php, app/Http/Requests/AI/*Conversation*.php, app/Http/Resources/ChatTemplateResource.php, app/Http/Streaming/**, app/Jobs/Ai/GenerateConversationTitle.php, app/Models/Chat*.php, app/Services/Chat/**, database/factories/Chat*.php, resources/js/components/AiChatSheet.vue, resources/js/components/ai/Chat*.vue, resources/js/components/ai/chat*.ts, resources/js/components/ai/ConversationPicker.vue, resources/js/components/ai/TurnToolGroups.vue, resources/js/components/ai/toolGroupSources.ts, resources/js/components/chat-templates/**, resources/js/composables/useAiChat*.ts, resources/js/composables/useChat*.ts, resources/js/pages/AI/Chat.vue, resources/js/pages/AI/Templates/**, resources/js/pages/Admin/AiConversations/**, tests/**/*Chat*Test.php, tests/**/*Conversation*Test.php, tests/**/MediaAgent*Test.php, tests/Feature/AI/ChatTemplates/**, tests/Feature/AI/Routing/**, tests/Feature/Services/Chat/**, tests/Unit/Services/Chat/** | .ai/rules/ai-chat.md |
| .ai/** | .ai/rules/ai-rules.md |
| app/Ai/** | .ai/rules/ai.md |
| app/** | .ai/rules/app.md |
| app/Services/Arr/**, app/Services/Sonarr/**, app/Services/Radarr/**, app/Services/Whisparr/**, app/Services/Prowlarr/ProwlarrClient.php, app/Cache/Services/SonarrCache.php, app/Cache/Services/RadarrCache.php, app/Cache/Services/WhisparrCache.php, app/Http/Controllers/Media/BaseArrController.php, app/Http/Controllers/Media/SeriesController.php, app/Http/Controllers/Media/MovieController.php, app/Http/Controllers/Whisparr/**, app/Http/Requests/Media/StoreSeriesRequest.php, app/Http/Requests/Media/StoreMovieRequest.php, app/Http/Requests/Media/DestroyArrMediaRequest.php, app/Http/Requests/Whisparr/**, resources/js/pages/Sonarr/**, resources/js/pages/Radarr/**, resources/js/pages/Whisparr/**, resources/js/components/series/**, resources/js/components/library-actions/**, tests/Feature/Services/Arr/**, tests/Feature/Services/Sonarr/**, tests/Feature/Services/Radarr/**, tests/Feature/Services/Whisparr/**, tests/Unit/Services/Arr/**, tests/Unit/Services/Whisparr/**, tests/Feature/Whisparr/**, tests/Feature/Media/SeriesControllerTest.php, tests/Feature/Media/MovieControllerTest.php, tests/Feature/Media/Library*Test.php, tests/Browser/Library*Test.php, tests/Browser/ArrLibraryOutageTest.php, tests/Browser/SeriesShowSectionsTest.php, tests/Browser/Whisparr*Test.php, tests/Unit/Architecture/ArrClientConstructionArchTest.php | .ai/rules/arr.md |
| app/Services/Benchmark/**, app/Ai/Decision/DecisionPrompt.php, app/Concerns/DecisionEventValidationRules.php, app/Concerns/VerifierCaseValidationRules.php, resources/js/components/benchmarks/** | .ai/rules/benchmarks.md |
| tests/Browser/** | .ai/rules/browser.md |
| app/Cache/Services/*.php | .ai/rules/cache-services.md |
| app/Notifications/Channels/** | .ai/rules/channels.md |
| app/Console/Commands/** | .ai/rules/commands.md |
| resources/js/components/** | .ai/rules/components.md |
| resources/js/composables/** | .ai/rules/composables.md |
| app/Concerns/*.php | .ai/rules/concerns.md |
| app/Http/Controllers/Admin/NotificationDestinationController.php, app/Http/Controllers/Settings/NotificationPreferencesController.php | .ai/rules/controllers-settings.md |
| app/Http/Controllers/** | .ai/rules/controllers.md |
| composer.json, composer.lock, package.json, pnpm-lock.yaml | .ai/rules/dependencies.md |
| app/Enums/*.php | .ai/rules/enums.md |
| app/Events/** | .ai/rules/events.md |
| database/factories/*.php | .ai/rules/factories.md |
| tests/Feature/** | .ai/rules/feature.md |
| ** | .ai/rules/general.md |
| app/Http/** | .ai/rules/http.md |
| app/Jobs/** | .ai/rules/jobs.md |
| resources/js/**/*.vue, resources/js/** | .ai/rules/js.md |
| app/Listeners/Ai/** | .ai/rules/listeners-ai.md |
| app/Services/MediaReplacement/**, app/Services/Bazarr/**, app/Ai/SubtitleAdvisor/**, app/Ai/Tools/Bazarr/**, app/Ai/Tools/Arr/*Subtitles*Tool.php, app/Ai/Tools/Arr/FindReplacementCandidatesTool.php, app/Cache/Services/BazarrCache.php, app/Concerns/Bazarr*ValidationRules.php, app/Concerns/ReplacementTargetValidationRules.php, app/Console/Commands/*Subtitle*.php, app/Console/Commands/*Replacement*.php, app/Enums/Bazarr*.php, app/Enums/MediaReplacement*.php, app/Enums/Subtitle*.php, app/Events/MediaReplacement*.php, app/Events/Subtitle*.php, app/Http/Controllers/Bazarr/**, app/Http/Controllers/Admin/MediaReplacement*.php, app/Http/Controllers/Media/MediaReplacementController.php, app/Http/Controllers/AI/SubtitleAuditBatchController.php, app/Http/Controllers/Webhooks/BazarrNotificationController.php, app/Http/Requests/Bazarr/**, app/Http/Requests/Admin/UpdateMediaReplacementSettingsRequest.php, app/Http/Requests/Media/*Replacement*.php, app/Http/Requests/Webhooks/BazarrNotificationRequest.php, app/Http/Resources/Bazarr/**, app/Jobs/*Subtitle*.php, app/Jobs/ReconcileBazarrConnection.php, app/Jobs/Middleware/*Subtitle*.php, app/Listeners/UpdateSubtitleCaseFrom*.php, app/Models/BazarrServiceLink.php, app/Models/MediaReplacementAttempt.php, app/Models/Subtitle*.php, app/Notifications/MediaReplacement*.php, app/Notifications/SubtitleCase*.php, app/Settings/BazarrAutomationSettings.php, app/Settings/MediaReplacementSettings.php, database/factories/Bazarr*.php, database/factories/MediaReplacement*.php, database/factories/Subtitle*.php, resources/js/components/bazarr/**, resources/js/components/media-replacement/**, resources/js/lib/subtitleAudit.ts, resources/js/pages/Bazarr/**, resources/js/pages/Admin/MediaReplacement/**, routes/bazarr.php, tests/Feature/Bazarr/**, tests/Feature/Services/Bazarr/**, tests/Feature/Services/MediaReplacement/**, tests/Unit/Services/Bazarr/**, tests/Unit/Services/MediaReplacement/**, tests/**/*Bazarr*Test.php, tests/**/*MediaReplacement*Test.php, tests/**/*Subtitle*Test.php | .ai/rules/media-replacement.md |
| database/migrations/*.php | .ai/rules/migrations.md |
| app/Models/*.php | .ai/rules/models.md |
| app/Notifications/** | .ai/rules/notifications.md |
| app/Observers/*.php | .ai/rules/observers.md |
| resources/js/pages/** | .ai/rules/pages.md |
| app/Services/AiUsage/Pricing/** | .ai/rules/pricing.md |
| app/Providers/*.php | .ai/rules/providers.md |
| app/Http/Requests/** | .ai/rules/requests.md |
| app/Http/Resources/** | .ai/rules/resources.md |
| routes/*.php | .ai/rules/routes.md |
| database/seeders/*.php | .ai/rules/seeders.md |
| app/Services/** | .ai/rules/services.md |
| app/Settings/*.php | .ai/rules/settings.md |
| app/Support/** | .ai/rules/support.md |
| tests/** | .ai/rules/tests.md |
