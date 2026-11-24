# Roadmap

What is still open, as of v1.62.2 (2026-11-23). Every item lands as its own pull
request. Finished items are removed here in the same pull request that finishes
them. New follow-ups from reviews are added here.

Markers: **decision** means the owner chooses first. **brainstorm** means a design
discussion comes before any plan. Unmarked items can be picked up as they are.

## Decisions

None open.

## Brainstorms

- **Frontend unit tests** (brainstorm): Vitest, `@vue/test-utils` and jsdom. For
  example, the `inferChoices` unit cases are covered only by browser tests today.
- **Project knowledge files** (brainstorm): `GLOSSARY.md` (domain terms and words
  to avoid), `docs/adr/` for decisions that are hard to reverse, and a rule to
  keep them current. When this lands, retire `TODO.md`.
- **Rule files per feature area** (brainstorm): one rule file each for the action
  queue, AI chat and tools, the *arr libraries and media replacement, each
  covering every layer. Migrate one area at a time, when it is touched.
- **Inertia live props** (brainstorm): replace `useRealtimeReload`, used in 14
  files.
- **Wider page layouts** (brainstorm): many pages, the connections pages among
  them, leave most of a desktop screen empty. Rework them to use the available
  width. First decide on a shared layout pattern, then list the pages to change.
- **AI runs and an MCP server** (brainstorm): the draft design covers AI runs, an
  MCP foundation and AI management tools.
- **AI features** (brainstorm):
    - Jev score questions pick the chat model and reasoning tier per message.
    - The scoped DecisionAgent prompt should list only the tools in scope.
    - `SearchMediaReleasesTool` should reach Whisparr.
    - `QueryActivityTool` uses `visibleTo(null)`, which is safe only while the AI
      pages are admin-only.
    - Turn on ToolSearch for the primary provider only, not the whole chain.
    - Benchmark replay should answer `OfferChoicesTool`.
    - Short-circuit a stuck download that is all samples.
    - A multi-download stuck-import verdict.
    - The price refresh's step reserve should bound tool calls.
    - Refresh the cached tool-group options.
    - Don't make `deliverTemplate` wait for the pins save.
- **Stopped-run leftovers** (brainstorm, part of the AI features):
    - Map stopped turns stored as failed before the change.
    - Guard the usage and failure listeners.
    - Don't count zero-token rows as requests.
    - Avoid two user messages in a row after an empty stopped turn.
    - Recheck the "Agent failed: " prefix on every laravel/ai upgrade.
- **Platform extras** (brainstorm):
    - PWA.
    - Onboarding.
    - Settings backup.
    - Keyboard shortcuts.
    - Resend a failed invite.
- **Host monitoring, later phases** (brainstorm): AI diagnosis and intrusion
  baselines (including restart-loop detection), then actions, then reflexes, then
  Dockhand events.

## Ready to pick up

- **AI routing minors**, from the review of each AI routing pull request.
    - Decision runs and the benchmark:
        - A deadline for the whole decision run.
        - Sweep window shorter than `retry_after`.
        - Sweep never-claimed pending benchmark results.
        - Apply the benchmark filter to the tool stats, rate limits and gates.
        - Provider names vs driver literals.
        - Entity-encoded fence tags.
        - Missing tests: picker unlock, the headroom `Throwable` path, call-id pairing
          end to end.
    - Scheduled benchmarks:
        - A budget hard cap reads as "every model regressed".
        - The regression banner needs a reload.
        - Re-judging leaves stored regressions stale.
        - More than 8 tier models break the estimate preview.
        - Email channel offered with only one `toMail()`.
        - A failed launch uses the "skipped" title.
        - DST handling.
    - Cross-run comparison:
        - The drawer shows the original score in neutral mode.
        - Duplicated task order, comparability rule, concurrency middleware and
          candidate lookup.
        - Test gaps.
    - Chat cases:
        - The 3-per-turn cap.
        - Draft id scoping.
        - Attachment storage, orphan files and preview.
        - Duplicated attachment mapping.
        - Re-judging a vanished image.
    - Decision reproduction:
        - Record the "payloads stored as JSON text" rule.
        - Compact parsed-prompt payloads.
        - Preview `aria-live`.
        - Empty event type allowed on the client.
    - Verifier:
        - `judges_differ` for unjudged scopes.
        - Unindexed `invocation_id` lookup.
        - Browser test gaps.
    - Overrides:
        - Page hints.
        - Skip-marker guard.
        - Show every error, not just the first.
        - Phone check.
    - Agreement panel:
        - Zero-height bar on a 0 % day.
        - "All" is unbounded when retention is 0.
        - The per-event pill can over-claim.
        - "1 false alarms" plural.
        - Table accessible name.
- **Series subtitle audit**:
    - A multi-chunk review refetches only at the end.
    - `outcomeLabel('queued')` returns an empty string.
    - The sweeper uses `get()` where `each()` would do.
    - Close-watcher race with a reload.
- **Host monitoring minors**:
    - Agent:
        - Crash-loop self-heal.
        - A hung inspection pins mounts.
        - Config apply saves every non-null value.
        - Empty `platform_url` in the install script.
        - `nosuid`/`nodev` on a read-only remount.
        - Run the extracted binary before moving it.
        - `platform_url` is read only at startup.
    - Platform:
        - One bad row stops the sweep.
        - The rollup assumes UTC.
        - Filesystem index.
        - Missing tests.
- **Bazarr history time** assumes Bazarr runs in `APP_TIMEZONE`.
- **AI batch requests (shelved 2026-11-24).** Provider batch APIs (about 50% off,
  results within 24 h) don't pay off yet: AI spend has been about $1/month since
  October, so batching everything that can wait saves at most about $0.50/month.
  The price refresh is a poor fit anyway (a 40-step tool loop inside a 255 s time
  box). Single-call tasks (decision verifier, benchmark judge) would be easy to
  batch, but tool-loop agents (`DecisionAgent`, benchmark cases) need the step loop
  rebuilt, and OpenRouter has no batch API. Revisit if benchmarks or webhook
  decisions start costing real money, or once laravel/ai supports batch requests.
- **AI price refresh batching** with a dedicated model, once laravel/ai supports
  batch requests.
- **Log provider stream errors.** laravel/ai never reports a `StreamErrorException`
  (an error the provider sent inside the stream), so a failed chat turn leaves
  nothing in the container logs; only the chat UI shows the message. Log it,
  with provider, model and request id, from a `StepFailed` listener.
- **Drop `NamespacedToolSearchOpenAiGateway`** once a laravel/ai release ships
  [laravel/ai#1259](https://github.com/laravel/ai/pull/1259) (deferred tools in a
  namespace), along with its registration in `AIServiceProvider`.

## Held dependency updates

- **vue-tsc**: held at 3.3.11. 3.3.12 to 3.3.14 break `types:check`
  (vuejs/language-tools#6240). Lift the hold when a release includes #6297.
- **TypeScript 7**: lift when vue-tsc (or its successor) checks `.vue` files on
  TypeScript 7. vue-tsc runs `typescript/lib/tsc`, which 7 no longer ships
  (vuejs/language-tools#6167).
- **Vite+**: Dependabot ignores `vite-plus` and `vite`. Bump `vite-plus`, the
  `vite` alias in `package.json` and the `vite` override in `pnpm-workspace.yaml`
  to the same release by hand (the release's `vite` dependency names the
  `@voidzero-dev/vite-plus-core` version).
- **pest-plugin-browser**: a long `script()` waits for the 30 s timeout
  (pestphp/pest#1852). Bump and recheck when a fixed release lands.
- **PHP 8.6**: move to it once it reaches general availability.

## Not planned

- qBittorrent and other torrent clients.
- Jellyfin.
- Translations (i18n).
