# Roadmap

What is still open, as of v1.62.2 (2026-11-23). Every item lands as its own pull
request. Finished items are removed here in the same pull request that finishes
them. New follow-ups from reviews are added here.

Markers: **decision** means the owner chooses first. **brainstorm** means a design
discussion comes before any plan. Unmarked items can be picked up as they are.

## Decisions

- **Email for every notification** (decision): only "Service update available" and
  "Scheduled benchmark alerts" have a mail template, so Notification Preferences
  shows Email disabled for the others. Should each of them get one?

## Brainstorms

- **Frontend unit tests** (brainstorm): Vitest, `@vue/test-utils` and jsdom. For
  example, the `inferChoices` unit cases are covered only by browser tests today.
- **AI runs and an MCP server** (brainstorm): the draft design covers AI runs, an
  MCP foundation and AI management tools.
- **AI features** (brainstorm):
    - Jev score questions pick the chat model and reasoning tier per message.
    - `SearchMediaReleasesTool` should reach Whisparr.
    - Short-circuit a stuck download that is all samples.
    - A multi-download stuck-import verdict.
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

- **Live props minors**, from the review of the live props pull request.
    - Viewers' Watch history (always paused) and filtered or later-page activity
      lists don't catch up after a reconnect until the user goes back to page 1;
      consider showing the "N new" notice after a missed reconnect.
    - The Emby Now Playing page (`useEmbyActivity`) has no reconnect catch-up.
    - Live reloads of the attempts index, attempt detail and servers index still
      run eager props (filter options, eager loads, push URL hint); make them
      lazy if reload traffic matters.
    - No browser test covers the attempt detail page's own
      `admin.media-replacement.{id}` channel or Whisparr V2 `scenes` liveness.
    - The Dashboard's reconnect reseed of recent activity can drop one row that
      arrives while the reload is in flight.
- **AI routing minors**, from the review of each AI routing pull request.
    - Agreement and gate charts:
        - Agreement "All" window: verdicts are read in chunks now, so memory is
          bounded, but the time still grows with retention. Aggregate in SQL if
          it gets slow.
- **AI batch requests (shelved 2026-11-24).** Provider batch APIs (about 50% off,
  results within 24 h) don't pay off yet: AI spend has been about $1/month since
  October, so batching everything that can wait saves at most about $0.50/month.
  The price refresh is a poor fit anyway (a 40-step tool loop inside a 255 s time
  box). Single-call tasks (decision verifier, benchmark judge) would be easy to
  batch, but tool-loop agents (`DecisionAgent`, benchmark cases) need the step loop
  rebuilt, and OpenRouter has no batch API. Revisit if benchmarks or webhook
  decisions start costing real money, or once laravel/ai supports batch requests.
- **Drop `NamespacedToolSearchOpenAiGateway`** once a laravel/ai release ships
  [laravel/ai#1259](https://github.com/laravel/ai/pull/1259) (deferred tools in a
  namespace), along with its registration in `AIServiceProvider`.
- **Drop `LegacyStuckImportCall`** once no AI tool invocation recorded before
  2026-10-16 is left (they are kept up to 90 days, so mid-January 2027): it
  rewrites those old single `download_id` `InspectStuckImportTool` calls when a
  benchmark case is drafted from them. The fixture migration keeps its own copy.
- **Statistics timezone minors**, found while fixing the watch heatmap's timezone:
    - Rollups that `statistics:aggregate` wrote on a non-UTC `APP_TIMEZONE`
      before the fix sit one offset late. Re-run `statistics:backfill` there once
      (it overwrites the buckets). The `library.added` rollups were already right
      and need no rerun. Watch-history rows that `emby:backfill-history` wrote
      before it moved `LastPlayedDate` onto the app clock also sit one offset
      off: re-run `emby:backfill-history` first (it rewrites the same rows), then
      `statistics:backfill`.

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

- Rewording the decision agent and second opinion prompts away from "reject"
  and "verifier". Only models read them, and a prompt change can move benchmark
  results.
- Typed Inertia pages through `#[TypefinderPage]`. The attribute does not read
  props from `Inertia::render()`: every prop type is still written by hand, as a
  TypeScript string in PHP, and the drift check compares prop names only. Props
  built from a resource already use its generated type.
- qBittorrent and other torrent clients.
- Jellyfin.
- Translations (i18n).
- Not waiting for the pins save in `deliverTemplate`. The wait is the right order:
  an existing chat's turn reads its pins from the server, and it costs only one
  round trip.
