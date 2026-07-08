# [1.6.0](https://github.com/pentacore/media-manager/compare/v1.5.0...v1.6.0) (2026-07-08)


### Bug Fixes

* **notifications:** prefix ntfy warning titles with service name ([c326bc0](https://github.com/pentacore/media-manager/commit/c326bc0685c7f563ae01da194c5c33e4ba51f53d))


### Features

* **admin:** external_url field on connection create/edit forms ([cf4afba](https://github.com/pentacore/media-manager/commit/cf4afba9dfe9be57f9b9a38c7d0d23ba9e00fe80))
* **notifications:** live NtfyChannel and mail via preference resolver ([0c7a120](https://github.com/pentacore/media-manager/commit/0c7a1208243c8aa4b43b7559e22e2616888cdccb))
* **notifications:** ntfy config and per-user topic ([fd2d0cd](https://github.com/pentacore/media-manager/commit/fd2d0cdb0882f6ab4d535500b75f8c0159e5372a))
* **notifications:** toNtfy payloads and preference-driven update notices ([9e09d78](https://github.com/pentacore/media-manager/commit/9e09d78775a6e4d3da63da9eb4d7278d12099c7b))
* **services:** add external_url column and linkUrl() helper ([c98d828](https://github.com/pentacore/media-manager/commit/c98d828ee13067e4cae4ce9b7e40e9233c91a132))
* **services:** user-facing links use external_url via linkUrl() ([7f70751](https://github.com/pentacore/media-manager/commit/7f7075152499a120fc994c3e423975f53c434ff7))
* **settings:** live mail/ntfy toggles, ntfy topic and test button ([0e0c88c](https://github.com/pentacore/media-manager/commit/0e0c88c1868a98b38ee6df932c155457c5ff42c9))

# [1.5.0](https://github.com/pentacore/media-manager/compare/v1.4.0...v1.5.0) (2026-07-08)


### Bug Fixes

* **ai:** measure pool caps against full period usage, not the display window ([74a1773](https://github.com/pentacore/media-manager/commit/74a1773e40adee82ea69f7f0f350aef59ad57fb3))
* **lint:** exclude .claude worktrees from eslint ([d8eebc9](https://github.com/pentacore/media-manager/commit/d8eebc9042b117c76c5385f248312a9790e3c633))
* pin package name to media-manager so container npm installs keep lockfile stable ([ef80524](https://github.com/pentacore/media-manager/commit/ef8052421de1e79dfbd0219c2470b7956a4fb992))
* **statistics:** buffer period boundary against clock-read drift ([723a8fd](https://github.com/pentacore/media-manager/commit/723a8fd1594f6b7b0fe9a0e1392d1bb3c5ea84e8))
* **statistics:** include cache tokens in ai.tokens rollup ([53a989e](https://github.com/pentacore/media-manager/commit/53a989eca1bc137237ae9cee2507e8a24e73d11f))
* **statistics:** post-merge review fixes for the statistics feature ([3854037](https://github.com/pentacore/media-manager/commit/385403788cdd9c4fab7bf2d18390bd0d5a5c6b31))
* **statistics:** read one period by window size in total/breakdown ([e38f1c9](https://github.com/pentacore/media-manager/commit/e38f1c94fcd211cbf1b23a3fa58e4726e59637ff))
* **ui:** export BreakdownMeter from the mm component barrel ([12acc33](https://github.com/pentacore/media-manager/commit/12acc33f778e6262933088632569b2db6075bf9a))
* update typefinder ([26f45e1](https://github.com/pentacore/media-manager/commit/26f45e1b140459fcc1c4a61a59d25f85600fda6c))


### Features

* **admin:** gate AI admin pages behind ai.enabled middleware ([07e54ea](https://github.com/pentacore/media-manager/commit/07e54ea50b2469457565271171eb87ffb38929ef))
* **admin:** TimeWindow-backed window filter on AI usage ([255686e](https://github.com/pentacore/media-manager/commit/255686e7528bb4b60136cb47039b2d6a9c97c57f))
* **ai:** add free usage pools schema and migrate per-row free tiers ([99f4a8a](https://github.com/pentacore/media-manager/commit/99f4a8ad4a968b96624ca6063b2a96791ec25026))
* **ai:** add FreeUsagePeriod enum with UTC calendar period math ([3df1799](https://github.com/pentacore/media-manager/commit/3df179990767033c87cddecaa2ea888cdfb70f76))
* **ai:** ai_model_rate_limits schema, model and price relation ([2db9bc0](https://github.com/pentacore/media-manager/commit/2db9bc01337f331161c62ee583a5b74af51c4b7c))
* **ai:** expose free usage pools on the AI usage dashboard ([ced1b28](https://github.com/pentacore/media-manager/commit/ced1b28794b30bb283758f9d7edf42980eabedc5))
* **ai:** free usage pool CRUD endpoints and prices-page wiring ([c04f798](https://github.com/pentacore/media-manager/commit/c04f798b4d64c7d638559a9ded40e64350eb0149))
* **ai:** persist model rate limits through price store/update ([194f3ca](https://github.com/pentacore/media-manager/commit/194f3ca995364af4ce6af2b179a4c38f2f1b11de))
* **ai:** pool-aware free usage status and period-bucketed discount ([cfa3077](https://github.com/pentacore/media-manager/commit/cfa30779e92d9021c37dbcad0fd660227128195c))
* **ai:** pools panel and pool assignment on the AI prices page ([31f2799](https://github.com/pentacore/media-manager/commit/31f2799016fe6f003235db3376c4de4f5068bd63))
* **ai:** rate limit editing in price dialogs and usage panel ([04cfc0d](https://github.com/pentacore/media-manager/commit/04cfc0dadfa166a722a1d4acfdc40aff7f59a0a1))
* **ai:** rate limit metric and rolling period enums ([1661ba6](https://github.com/pentacore/media-manager/commit/1661ba6953439f96b9b57dd4263c278585035e93))
* **ai:** render free usage pools panel on the AI usage dashboard ([bd80c11](https://github.com/pentacore/media-manager/commit/bd80c113109ed6e309145869db52b6b75ce61845))
* **ai:** rolling-window rate limit status on the usage dashboard ([c9d2e85](https://github.com/pentacore/media-manager/commit/c9d2e85f4d37c2629595cb6d2d65c0cf417eb4ec))
* **emby:** shared TimeWindowFilter on watch history page ([45a959f](https://github.com/pentacore/media-manager/commit/45a959f400846c3ef979268a6253601133b6c47e))
* **emby:** TimeWindow-backed since filter on watch history ([60a9a51](https://github.com/pentacore/media-manager/commit/60a9a519baf98cd3dcbbb0dc93fcf7d8d2713d05))
* **media:** real posters in series and movies index views ([1b367dc](https://github.com/pentacore/media-manager/commit/1b367dc91aa6b4b8154fa8960f00d921c71572a5))
* **requests:** render tmdb posters on request cards ([782adcc](https://github.com/pentacore/media-manager/commit/782adcc13e2f0dc40c491351ffcd55a09ac6fbf2))
* **requests:** resolve seerr poster paths alongside titles ([dc83af2](https://github.com/pentacore/media-manager/commit/dc83af2857b0c1389c8004d25b47d6c8ca1563dd))
* **search:** render result posters ([73709ec](https://github.com/pentacore/media-manager/commit/73709ec8e09409f947ff16323778a1dffa071313))
* **statistics:** split approval and resolved rate stat cards ([3914cfa](https://github.com/pentacore/media-manager/commit/3914cfa5970440731798ee0693cf69aba5786695))
* **stats:** admin operational statistics page ([cceede3](https://github.com/pentacore/media-manager/commit/cceede33a65654701e57d27c54078b9d291fa3b3))
* **stats:** hourly statistics aggregator with watermark ([18f9afb](https://github.com/pentacore/media-manager/commit/18f9afbe93b130fcf6e57f083c99765361a99317))
* **stats:** ingest listener for trim-safe webhook streams ([fc82eb3](https://github.com/pentacore/media-manager/commit/fc82eb34133049aeaf5456a3156d735bc412f1e5))
* **stats:** navigation entries for statistics pages ([0e73f99](https://github.com/pentacore/media-manager/commit/0e73f99d2f7a2dd06f6f6078440259efc708aa69))
* **stats:** rollup and service-metric retention pruning ([70d4bab](https://github.com/pentacore/media-manager/commit/70d4babb9e8104c8d5246ac74b273d14f4ff0393))
* **stats:** service gauge poller and daily library snapshot ([7f7c0f5](https://github.com/pentacore/media-manager/commit/7f7c0f5b000f983460a966a12b15fc63887532ee))
* **stats:** stat_rollups table, model, factory ([c2b9eae](https://github.com/pentacore/media-manager/commit/c2b9eaef7310e135c19450269f0fa60ffd88ee40))
* **stats:** statistics:backfill command ([268cd13](https://github.com/pentacore/media-manager/commit/268cd13a837c91ec1f6598e8edbea27e1e5ba030))
* **stats:** StatisticsRepository read layer ([8f6b119](https://github.com/pentacore/media-manager/commit/8f6b1198ad6101c1f02bc1fdad9dc47be392633e))
* **stats:** StatsRecorder additive/overwrite upsert service ([07e6113](https://github.com/pentacore/media-manager/commit/07e61138849cc3e6fc0bebb72f2e8eec2a32681b))
* **stats:** token-gated prometheus metrics endpoint ([23b647e](https://github.com/pentacore/media-manager/commit/23b647e1d83f77308db8e1fae65dfbcf2b0f1330))
* **stats:** user statistics page with charts ([16b8d5e](https://github.com/pentacore/media-manager/commit/16b8d5e0917b3e173294756e6f311b0a0d357b82))
* TimeWindow enum for shared table time filters ([38637b3](https://github.com/pentacore/media-manager/commit/38637b3b10b142dd42102bced36548bdf0739b1f))
* **ui:** hide AI admin sidebar links when AI is disabled ([e56b452](https://github.com/pentacore/media-manager/commit/e56b4525bd8e088fb2835709c6be6584ba84bc61))
* **ui:** optional src prop on Poster with gradient fallback ([76eb979](https://github.com/pentacore/media-manager/commit/76eb9798bc622bb63910e80740b3dd79806da6db))
* **ui:** shared TimeWindowFilter component on AI usage page ([b2641e5](https://github.com/pentacore/media-manager/commit/b2641e599e7e8cb7b83f2a417932adce2b19e6da))
* **ui:** tmdb poster url helper ([16eda65](https://github.com/pentacore/media-manager/commit/16eda65dc804b7ffaa3d2456fa45212f48a510a4))

## [1.3.1](https://github.com/pentacore/media-manager/compare/v1.3.0...v1.3.1) (2026-06-23)


### Bug Fixes

* **ci:** create local refs for configured release branches ([7c71ada](https://github.com/pentacore/media-manager/commit/7c71adada1503558a14f94e20d1e99a405fac704))

# [1.3.0](https://github.com/pentacore/media-manager/compare/v1.2.0...v1.3.0) (2026-06-23)


### Bug Fixes

* **auth:** verify email for SSO and Emby logins ([03c65ee](https://github.com/pentacore/media-manager/commit/03c65ee1ddcd0875343743201dfa915c34ff1b99))


### Features

* **ai:** DecisionAgent — autonomous handling of inbound webhook events ([#41](https://github.com/pentacore/media-manager/issues/41)) ([b824fb5](https://github.com/pentacore/media-manager/commit/b824fb581cbdb358e775e6311ddd6c45f44d4978))

# [1.2.0](https://github.com/pentacore/media-manager/compare/v1.1.1...v1.2.0) (2026-05-03)


### Bug Fixes

* **emby:** collapse playback events into one row per PlaySession ([17a8194](https://github.com/pentacore/media-manager/commit/17a8194fe56f5adef67fd10774b2e7fe6883080a))


### Features

* **emby:** backfill watch history from Emby REST API ([b8d9b89](https://github.com/pentacore/media-manager/commit/b8d9b89d44668c2f2182dce5f58dea612a67318f))

## [1.1.1](https://github.com/pentacore/media-manager/compare/v1.1.0...v1.1.1) (2026-05-02)


### Bug Fixes

* fix trigger docker build on tag ([11ca4aa](https://github.com/pentacore/media-manager/commit/11ca4aad7da76c23495d367126fca25026a7b078))

# [1.1.0](https://github.com/pentacore/media-manager/compare/v1.0.3...v1.1.0) (2026-05-02)


### Bug Fixes

* **arr:** only treat existing Webhook notifications as upsert targets ([f0019b1](https://github.com/pentacore/media-manager/commit/f0019b1ac93e91c97bc8f0606ecd8500a54595c3))
* **ui:** use clipboard helper to avoid undefined navigator.clipboard in HTTP contexts ([46cd31b](https://github.com/pentacore/media-manager/commit/46cd31bbb482389a3c4a84bf98da423eb1fa1411))
* **webhooks:** extract event_type per service instead of camelCase only ([8ffbd71](https://github.com/pentacore/media-manager/commit/8ffbd713d4a133da7967d9bba5e638c3366cca6f))


### Features

* **admin:** add configureWebhook action to push our webhook into Sonarr/Radarr/Prowlarr ([709edee](https://github.com/pentacore/media-manager/commit/709edeeab3f46930a159b14da22cac542f31e1c0))
* **arr:** add notification CRUD + configureWebhook upsert on ArrClient ([bc3a4d9](https://github.com/pentacore/media-manager/commit/bc3a4d9a608a31a331cc659528ed3ffc6386990e))
* **ui:** add 'Configure on service' button on connection edit page ([2fd699b](https://github.com/pentacore/media-manager/commit/2fd699bb7c466c51d29672f936448f1fc7ac0900))
* **ui:** add copyToClipboard helper with secure-context fallback ([4253914](https://github.com/pentacore/media-manager/commit/4253914759a1f1fc8f57e0b6b1d2fca42185b7e8))

## [1.0.3](https://github.com/pentacore/media-manager/compare/v1.0.2...v1.0.3) (2026-05-02)

## [1.0.2](https://github.com/pentacore/media-manager/compare/v1.0.1...v1.0.2) (2026-05-02)


### Bug Fixes

* **release:** checkout branch tip, not workflow_run head_sha ([d1a2cdb](https://github.com/pentacore/media-manager/commit/d1a2cdb9482c6404d714cb495cf064439e82078a))

## [1.0.1](https://github.com/pentacore/media-manager/compare/v1.0.0...v1.0.1) (2026-05-02)


### Bug Fixes

* **reverb:** inject config at runtime via meta tag ([2a80ead](https://github.com/pentacore/media-manager/commit/2a80ead70e747c177956c618342eb270a57fbbd1))

# 1.0.0 (2026-05-01)


### Bug Fixes

* add success badge variant and status variant ([9c5b5fe](https://github.com/pentacore/media-manager/commit/9c5b5fef6de43ab9703bc46b6c6ff04dfa7868be))
* **ai-usage:** cast scenario rate parameters to ::numeric in Postgres ([c137569](https://github.com/pentacore/media-manager/commit/c137569462326f26912f4350fe7e8cb41846c428))
* **ai-usage:** match dated model ids against base price + emit ISO timestamps ([b3d1231](https://github.com/pentacore/media-manager/commit/b3d123172417ac235b13a4483327a04477283ac2))
* **ai:** add object schema for ProposeWorkflowTool steps array ([4cc25d6](https://github.com/pentacore/media-manager/commit/4cc25d6dced77114885613e777e167f6b4532513))
* **ai:** address Phase 2 review findings ([a62f13c](https://github.com/pentacore/media-manager/commit/a62f13c03b51d7567e03fb4c3e8690914944b3a7))
* **ai:** drop double-bound Event::listen calls in AIServiceProvider ([1f7370c](https://github.com/pentacore/media-manager/commit/1f7370c92691d6a10b45bb7beed6d3050beb0f28))
* **ai:** log full provider response body on AI request failure ([cff7c48](https://github.com/pentacore/media-manager/commit/cff7c486c691b20ffdf933ec0712ed9f1739e525))
* **ai:** mark every UpsertModelPriceTool property as required ([9fbc4b4](https://github.com/pentacore/media-manager/commit/9fbc4b4cf62a29a1f5579d4a6eaf2636a7a22ac7))
* **ai:** mark optional tool params required+nullable for OpenAI strict mode ([cf0f1a1](https://github.com/pentacore/media-manager/commit/cf0f1a1b6452bcbcf4337c75f3a63ce4faf5f369))
* **ai:** reset chat conversation on agent switch ([ae6a8ff](https://github.com/pentacore/media-manager/commit/ae6a8ff59021019b947cd356c4a5ac5dd51b01f5))
* **ai:** route to OpenAI by default; document the real env keys ([5e8c65a](https://github.com/pentacore/media-manager/commit/5e8c65a63b50ea08a45a147770700c08b0f28bd9))
* **ai:** safe-encode tool results + refresh README for new architecture ([9413f71](https://github.com/pentacore/media-manager/commit/9413f71fb0730edb8665a588f0fdd97650fe4f45))
* **ai:** steer TMDB/Trakt fallback on tool_failed envelope (not on raw exception text) ([2544880](https://github.com/pentacore/media-manager/commit/254488084d0189271766b7e6b6bc618dfe0ad0cf))
* all enums should use EnumUtils ([c44f7dc](https://github.com/pentacore/media-manager/commit/c44f7dc8250932d735f337fb553bef92bbeb688d))
* **ci:** force sqlite for js-lint type generation ([2e48a8a](https://github.com/pentacore/media-manager/commit/2e48a8afa82ac179ddfa1feedd5baff162c872e4))
* **ci:** migrate sqlite before typefinder so schema introspection works ([def95b0](https://github.com/pentacore/media-manager/commit/def95b0cd029c93f3b62641bf418dbbf0f471c32))
* **ci:** revert AI mode default + install Playwright browsers ([fd3b7e4](https://github.com/pentacore/media-manager/commit/fd3b7e4429c685d01376c39686f449c54f3b7922))
* cleanup and performance fixes ([e8d5c05](https://github.com/pentacore/media-manager/commit/e8d5c051b89dd360d2fb92fe44a8bf6af07be324))
* connection table restructuring, trigger check jobs on creation/update ([52cb2ef](https://github.com/pentacore/media-manager/commit/52cb2efc8b8a80800eee532330785f5854de1116))
* **docker:** create storage framework dirs before booting artisan in builder ([7f05ee5](https://github.com/pentacore/media-manager/commit/7f05ee515a206f1095a4437c6d6b2089378e6fe1))
* drop Postgres types + views on test DB refresh ([07445d7](https://github.com/pentacore/media-manager/commit/07445d7736cfbffdb80458caf730073bf9aeed0c))
* **env:** MEDIAMANAGER_AI_MODE=executive in dev .env.example ([954754a](https://github.com/pentacore/media-manager/commit/954754aee65585a254fbe0c58b19443b5185928a))
* **fortify:** keep views=true default; required for Inertia view bindings ([e46ccdf](https://github.com/pentacore/media-manager/commit/e46ccdf79dbb29736f4e2012a67487cd8a64519f))
* image ref ([4cb7d47](https://github.com/pentacore/media-manager/commit/4cb7d470b9a8da2d11cfcdf38b56f839bae104b6))
* **library:** humanize history + queue status labels ([0cd7c64](https://github.com/pentacore/media-manager/commit/0cd7c6472ec1ee76daacca6c444b97d755abea14))
* make default seeded user an admin ([10ee53b](https://github.com/pentacore/media-manager/commit/10ee53bda1211c7518de1e7c6f790de58a4d082f))
* null-safe JSON coercion on all service client array returns ([3b95d81](https://github.com/pentacore/media-manager/commit/3b95d8135cf5809b2e1207d18a673b823738d02c))
* **palette:** bind global Cmd/Ctrl+K listener inside onMounted ([384856d](https://github.com/pentacore/media-manager/commit/384856d487e8dde9a8202530456d7e53dfa11d90))
* price fetcher more providers ([48a5525](https://github.com/pentacore/media-manager/commit/48a5525da8cb7f7d22754af947649a8d0525f881))
* **prowlarr:** clear PROWLARR_* env in ServiceConnectionSeeder test setup ([4aa0e52](https://github.com/pentacore/media-manager/commit/4aa0e52de81d37954397dbbd54739d31f2513e0d))
* **prowlarr:** trim indexer payload + handle deferred prop in Edit.vue ([0b4f93f](https://github.com/pentacore/media-manager/commit/0b4f93fe331aad67d329ba9b271deb34a890ee8f))
* **realtime:** close four stale-data gaps on the live pages ([d86cd0f](https://github.com/pentacore/media-manager/commit/d86cd0f8838780512ca6624e58474782364dae2d))
* reasoning field not being retained ([5562993](https://github.com/pentacore/media-manager/commit/556299395da4c7bfb8a171a479039c8f02b782bf))
* **search:** split per-section result count ([fbb0ebc](https://github.com/pentacore/media-manager/commit/fbb0ebcc206b06c5adab19350f9b498a7a3ed8ae))
* **search:** surface existing Seerr requests via /search + details ([dbfa8e2](https://github.com/pentacore/media-manager/commit/dbfa8e23bc9db56d0698d258167ef0f7e5880930))
* **seerr:** correct request status filters and available count ([9fb1a71](https://github.com/pentacore/media-manager/commit/9fb1a7151495e3dd1832bf4e6618d03cd4749035))
* **seerr:** point Open-in-Emby button at the Emby connection ([3eecee8](https://github.com/pentacore/media-manager/commit/3eecee8751ad1548e7187919117d1f1570c1a7a7))
* stop leaking AI exception messages and authenticate GitHub release lookups ([d505ac5](https://github.com/pentacore/media-manager/commit/d505ac5075a5a62a6fdbe6756807ffe23759568b))
* **test:** unset SABNZBD env in ServiceConnectionSeederTest ([a5f88a1](https://github.com/pentacore/media-manager/commit/a5f88a19472cc6c9e16d3fa881cb77eba80b3b57))
* transient vs permanent failure handling in ExecuteActionRequest ([b129270](https://github.com/pentacore/media-manager/commit/b129270a5622b145046e44d2d28b27b613be9a46))
* tuning production image ([d2a7693](https://github.com/pentacore/media-manager/commit/d2a7693199e52ee5c6dc71d1da73643dd7036f35))
* **types:** add download_id to QueueRow + drop preserveScroll ([926e0ff](https://github.com/pentacore/media-manager/commit/926e0ffece708a828b030efe751d0abcb3084c89))
* **ui:** humanize activity-log labels, fix narrow-window clipping, warm intervention badge ([75586a1](https://github.com/pentacore/media-manager/commit/75586a1dc7de5fdcc45f508a37e7aaa2101818ea))
* useNotifications subscribes to the dashboard channel ([3135726](https://github.com/pentacore/media-manager/commit/3135726c0cce67538474ae98c4469ed54cb50a7c))


### Features

* a bunch of features and fixes ([182060b](https://github.com/pentacore/media-manager/commit/182060bed5b6c23a43059322b4bfe384bb3d2640))
* ActivityLog entries for ActionRequest lifecycle ([109efe9](https://github.com/pentacore/media-manager/commit/109efe90a072786439c4244f457ae4a50f49ed7f))
* add a custom user agent to clients ([50e07d4](https://github.com/pentacore/media-manager/commit/50e07d4c659648596a23d43254f74ffc16118eac))
* add admin navigation to sidebar ([31790cb](https://github.com/pentacore/media-manager/commit/31790cb03f6e593a922de6a25eb517e9f65f25dd))
* add all Phase 1 database migrations ([0fe4f03](https://github.com/pentacore/media-manager/commit/0fe4f03912837fec1b26906b67b309c2c0fc3b1c))
* add all Phase 1 models and factories ([633b85e](https://github.com/pentacore/media-manager/commit/633b85e230a928f8726a6419b883bbee5fb580cd))
* add Authentik OIDC authentication ([f60a07c](https://github.com/pentacore/media-manager/commit/f60a07cd42a7c8ce6164e4d9b5fe4069a5ea158e))
* add create local user from admin panel ([5e8eea4](https://github.com/pentacore/media-manager/commit/5e8eea48bbaa0132b7256ad5b110033bce9c1e38))
* add Emby credential authentication ([5ab51f6](https://github.com/pentacore/media-manager/commit/5ab51f65f7f9e961b2620f4ae2cf6a5cb2ca939b))
* add EnsureUserHasRole middleware ([8223762](https://github.com/pentacore/media-manager/commit/8223762cb7dee70db9e3de30d466157f0e6671de))
* add FindOrCreateSsoUser action with first-user-admin logic ([75af683](https://github.com/pentacore/media-manager/commit/75af683b6e861c9d16f97f3bf8d9dba6b2c617e7))
* add foundation enums and enable RefreshDatabase ([83f964d](https://github.com/pentacore/media-manager/commit/83f964d3f9bb51bf0792c021d1aa6d499e4f8d71))
* add Open-in-Service button across service pages ([658e51b](https://github.com/pentacore/media-manager/commit/658e51b4cc90a5e0f69e3af4b7d4777729d98a73))
* add option to set password directly when creating users ([684acc3](https://github.com/pentacore/media-manager/commit/684acc31e499e9798313d245725d54a7721b14cd))
* add service connection admin CRUD ([16f1102](https://github.com/pentacore/media-manager/commit/16f110285fc6103d884c8f70b0978e729ce9597a))
* add service connection admin frontend pages ([6f335a3](https://github.com/pentacore/media-manager/commit/6f335a32f951b2ff3204de093f171effdf311882))
* add user management admin backend ([7a14551](https://github.com/pentacore/media-manager/commit/7a14551d74559e6a9ce62ce3bcc9471a2da95a14))
* add user management admin frontend ([dd86cda](https://github.com/pentacore/media-manager/commit/dd86cdabe97fc5b51867bf3df7a66256a39bfc47))
* add webhook endpoint with token authentication ([a452400](https://github.com/pentacore/media-manager/commit/a4524007fcc6c8f55e06afe26b0e973ffe1b1d63))
* **admin:** read-only Jobs page ([5c73b14](https://github.com/pentacore/media-manager/commit/5c73b148789b27184691fed26c169a755de10ddf))
* **admin:** webhook log viewer + TODO checkboxes ([b23e0c5](https://github.com/pentacore/media-manager/commit/b23e0c5a496d8003faebbd36d3a80e3d5c0459b4))
* **ai-prices:** queue refresh job + websocket lifecycle ([0188a78](https://github.com/pentacore/media-manager/commit/0188a78e0e5621ba9fe18905e6fbbdc337f8f1f9))
* **ai-usage:** per-call price snapshot, drill-down detail modal, and triggering-user attribution ([ca44c8a](https://github.com/pentacore/media-manager/commit/ca44c8af86cfffd21618e61d5aa75a0d1afd91cc))
* **ai-usage:** persist agent response and make detail modal scrollable ([707714b](https://github.com/pentacore/media-manager/commit/707714b623f35099a4840925844635a7229d07ef))
* **ai-usage:** subtract per-model free quotas from spend + new panel ([0d9d5fb](https://github.com/pentacore/media-manager/commit/0d9d5fbb9f975124ed98c2e06061e000e81acfa3))
* **ai:** AddSeriesTool + add_series executor + seed ([97b5e5a](https://github.com/pentacore/media-manager/commit/97b5e5a87d60ac6083f97ad52df8ec4653c68893))
* **ai:** admin usage dashboard and model-price CRUD ([07e8a7b](https://github.com/pentacore/media-manager/commit/07e8a7b59ee82c26807f6376823cfba9f6d85f34))
* **ai:** ai_proposed_workflows table + model + status enum ([1af2f99](https://github.com/pentacore/media-manager/commit/1af2f9901209420213d35b3e1adfd14053bf1e75))
* **ai:** BaseTool with risk-tiered safety and never-throw guarantee ([b14b833](https://github.com/pentacore/media-manager/commit/b14b833d049a8c61d51790eadc1ab58bd94d314c))
* **ai:** batch pricing fields + tier toggle ([0ff3ea0](https://github.com/pentacore/media-manager/commit/0ff3ea0ad2584d54b7fcac0a55cc3781919484af))
* **ai:** chat confirm card + ProposeWorkflow continuation handling ([62ecd9e](https://github.com/pentacore/media-manager/commit/62ecd9e5551288a84a8b1cd38d6b7ec48ec9812c))
* **ai:** chat uses MediaAgent — drop agent picker from UI and API ([60755f1](https://github.com/pentacore/media-manager/commit/60755f142f58a593ba3e0f11e844656e45946e24))
* **ai:** conversation store decorator heals orphan tool calls ([12a3cb8](https://github.com/pentacore/media-manager/commit/12a3cb86fc69822fa58e542fcf15d78ace491b16))
* **ai:** Emby MarkAsWatched + MarkAsUnwatched tools (SafeWrite) ([0ac3057](https://github.com/pentacore/media-manager/commit/0ac3057d1bee62d74aaf66cb8965637e3901529b))
* **ai:** Emby tools (NowPlaying, WatchHistory, LibraryScan) on BaseTool ([6a126d3](https://github.com/pentacore/media-manager/commit/6a126d3ca00de4d4faa09776a191914f18ee379d))
* **ai:** MediaAgent registers Phase-2 tools + workflow-batching guidance ([f39e915](https://github.com/pentacore/media-manager/commit/f39e915e338a15dfc3c6bd139573e4b95611b130))
* **ai:** MediaAgent registers Phase-3 metadata tools + recommendation guidance ([271baab](https://github.com/pentacore/media-manager/commit/271baab02291f321ec70dca710874caa79013d51))
* **ai:** MediaAgent unified — 19 tools, single system prompt ([fa53e26](https://github.com/pentacore/media-manager/commit/fa53e26ec84352af04887d055b03a598050e6403))
* **ai:** MonitorSeries + SetSeriesQualityProfile tools + executors ([3ddbb4b](https://github.com/pentacore/media-manager/commit/3ddbb4b8c6728a657b538c3c3442008a5cba8f5c))
* **ai:** monthly budget caps — soft notify + hard halt ([512a650](https://github.com/pentacore/media-manager/commit/512a6509c8278675d5daa8d3a9f0053f9bc54ea9))
* **ai:** per-agent model selection, usage telemetry, advisory mode ([a686170](https://github.com/pentacore/media-manager/commit/a6861705e17ee2841d8651110bed78e1b362eb4b))
* **ai:** PriceFetcherAgent — live online pricing refresh + tests ([fa4b0b8](https://github.com/pentacore/media-manager/commit/fa4b0b8c1aa00573c1a7b5aeae2cae5bebf919f9))
* **ai:** ProposeWorkflowTool — store proposal, return awaiting_confirmation ([1a525d8](https://github.com/pentacore/media-manager/commit/1a525d804e02ebd5127c72ca8910c8da82d39e0a))
* **ai:** Prowlarr tools (SearchIndexers, ListIndexers) on BaseTool ([077df90](https://github.com/pentacore/media-manager/commit/077df90d03493d1eca64eca23494e7717392120c))
* **ai:** prune-proposed-workflows command + scheduler + browser e2e ([c3117f5](https://github.com/pentacore/media-manager/commit/c3117f56a8ca1fa451a8d1a88f63865779583125))
* **ai:** Radarr Add/Monitor/SetQualityProfile tools + executors ([af7c591](https://github.com/pentacore/media-manager/commit/af7c5915f108b3ccf22857a6f164330283d3d7c1))
* **ai:** Radarr tools (Search/Get/Delete) on BaseTool ([91e4fd4](https://github.com/pentacore/media-manager/commit/91e4fd48169fb62148a0b7d01e1eadce5c74df8b))
* **ai:** refresh prices, grouped model select ([bd2a08f](https://github.com/pentacore/media-manager/commit/bd2a08f6f98a82d23f04ad3a633910342dc83f97))
* **ai:** Seerr Approve/Decline tools + executors ([dfef265](https://github.com/pentacore/media-manager/commit/dfef265667c7322e05654f4a5c7b13ff03fe47d7))
* **ai:** Seerr tools (Search/Discover/GetTitle/ListPending/Cleanup) on BaseTool ([c8e437e](https://github.com/pentacore/media-manager/commit/c8e437e4924bbd5bff6f1f5b4257e23542611e4d))
* **ai:** Sonarr tools (Search/Get/Delete) on BaseTool ([cf06ffb](https://github.com/pentacore/media-manager/commit/cf06ffb70a8a7c45591c7cd5b8be488469759df2))
* **ai:** system tools (GetServiceStatus, QueryActivity) on BaseTool ([02791bc](https://github.com/pentacore/media-manager/commit/02791bca2e026be6c62d23b1dc1121bace8f8d46))
* **ai:** TMDB tools — TmdbGetTitle / TmdbGetSimilar / TmdbGetCredits ([7810d1f](https://github.com/pentacore/media-manager/commit/7810d1ff010d9a214bdf66e406b33e34b969089c))
* **ai:** TmdbClient::getSimilar + getCredits ([75f9924](https://github.com/pentacore/media-manager/commit/75f99240091fcaf9ce494ff30228fed0909d95a1))
* **ai:** TmdbClient::getTitle + services config entries (TMDB + Trakt) ([21a3482](https://github.com/pentacore/media-manager/commit/21a3482c2ad4184c4b6ceb2c3cdd468323c1c182))
* **ai:** Trakt tools — TraktGetTrending / TraktGetPopular / TraktGetList ([a2d71cb](https://github.com/pentacore/media-manager/commit/a2d71cbbc052a5be9bcb1ad1644ae1b79992a914))
* **ai:** TraktClient — getTrending / getPopular / getList ([0a655de](https://github.com/pentacore/media-manager/commit/0a655de4e276404f91af5c7047ba769a6f3bb861))
* **ai:** what-if scenario on usage dashboard ([a9c6c77](https://github.com/pentacore/media-manager/commit/a9c6c7759d37545db78cbdfbc25316342a02bdc7))
* broadcast activity, version, connection lifecycle, processed webhooks ([366acd0](https://github.com/pentacore/media-manager/commit/366acd0015be8e08971c4895562ac7014ff1d17a))
* **cache:** BaseServiceCache abstract + mediamanager.cache config (TTLs + driver) ([fbdf594](https://github.com/pentacore/media-manager/commit/fbdf594c412eb301d27940a6765dda529525506a))
* **cache:** ProwlarrCache wraps indexer reads (TTL-only invalidation) ([cc72d6e](https://github.com/pentacore/media-manager/commit/cc72d6e07f5fd5f1692f556e7cfe8277c3229f23))
* **cache:** RadarrCache wraps 5 read methods + busts on webhook + local writes ([fb3e932](https://github.com/pentacore/media-manager/commit/fb3e932b87b4f8f1934aff76885de08957fe136c))
* **cache:** SeerrCache wraps 8 read methods + busts on webhook + local writes (controller + actions) ([4989693](https://github.com/pentacore/media-manager/commit/4989693fd0962d2d8f01c412d4e01fca70bd80a6))
* **cache:** SonarrCache wraps 6 read methods + busts on webhook + local writes ([67e5dbf](https://github.com/pentacore/media-manager/commit/67e5dbf4964a1184a48204754158cf72136426ad))
* **cache:** TmdbCache wraps title/similar/credits (TTL-only metadata) ([17320b9](https://github.com/pentacore/media-manager/commit/17320b93ae9d853a81f77d5993f0a7dbcb18284d))
* **cache:** TraktCache wraps trending/popular/list (TTL-only) ([05df957](https://github.com/pentacore/media-manager/commit/05df9574b6c6bf5ca3697de19557f02688773cdb))
* check Emby version via MediaBrowser/Emby.Releases ([e22ab12](https://github.com/pentacore/media-manager/commit/e22ab12d9931219eaf45a484f13a7923de30ebb2))
* Cmd+K command palette and live Now Playing ([2ca2ae2](https://github.com/pentacore/media-manager/commit/2ca2ae26f66922d0f5f4df29bd390131935858ba))
* configure Authentik OIDC provider ([f871dfc](https://github.com/pentacore/media-manager/commit/f871dfc843301f25dd0f51dc6173b0bac5e619a8))
* **console:** users:create command ([f2bad73](https://github.com/pentacore/media-manager/commit/f2bad73ef9b77220812fbccb807c33fa76eff7f1))
* dedicated Activity Log page ([7e96ce6](https://github.com/pentacore/media-manager/commit/7e96ce6320bb242d169dcdaf9fc2d6a98dabf42e))
* demo:fake-webhooks artisan command for realtime smoke-testing ([8c9aca2](https://github.com/pentacore/media-manager/commit/8c9aca24fee7cfe67ca2edd59091c7b9edc49bd9))
* **d:** per-request AI mode + price refresh + notifications + topbar AI ([f6e5e90](https://github.com/pentacore/media-manager/commit/f6e5e90b89baf694b347ef8f58c04e3d88d2fa99))
* **emby:** Profile self-link card + admin link-by-username + Emby import ([459f671](https://github.com/pentacore/media-manager/commit/459f671901d84cacde9edd9ec55feae548d867da))
* expand Seerr client + point at canonical repo ([76fc272](https://github.com/pentacore/media-manager/commit/76fc272dc5e90874994dc70eb0f6c7a8f33794f1))
* expanded webhook coverage (Radarr + Seerr + Sonarr events) ([39b54ab](https://github.com/pentacore/media-manager/commit/39b54abb15f39ac610dae5a629dec5c85bed4e8d))
* **filters:** add Today preset to time-range pickers ([c8c2a52](https://github.com/pentacore/media-manager/commit/c8c2a5254b63db50842da0e458c746f393b424f2))
* generic realtime composables (useRealtimeList, useRealtimeReload, useConnectionState) ([2c294d8](https://github.com/pentacore/media-manager/commit/2c294d8212a21374dfb35334fae2366a5d675cf5))
* **library:** add a History tab next to the queue view ([2db67d3](https://github.com/pentacore/media-manager/commit/2db67d354af2f7f114102921153a41a07899ddc0))
* **library:** admin actions to remove or blocklist queue items ([384346f](https://github.com/pentacore/media-manager/commit/384346fb7806030a90d8d280e4cf7a52e4da8ba7))
* **library:** combined Sonarr + Radarr download queue page ([0c0eb4a](https://github.com/pentacore/media-manager/commit/0c0eb4a65ac2263ec0d956af9a23d820fd18692e))
* **library:** force-grab a delayed Sonarr/Radarr queue item ([18bbc83](https://github.com/pentacore/media-manager/commit/18bbc839ab44951e36db95b820c6f119b8584236))
* **library:** handle ManualInteractionRequired webhooks + intervention badge ([47b9a64](https://github.com/pentacore/media-manager/commit/47b9a645e9bc2294885d192e71377874dafa5f9d))
* **library:** link to Sonarr/Radarr activity log from index pages ([c056cd7](https://github.com/pentacore/media-manager/commit/c056cd7f8f846441560822a2a3268be341bc4c0b))
* **library:** manual import dialog for stuck queue items ([7fdc82a](https://github.com/pentacore/media-manager/commit/7fdc82ad7409455ad94ca0babf633bf3c990ea5f))
* live Activity Log, Action Requests, Watch History, Dashboard ([8f35c7f](https://github.com/pentacore/media-manager/commit/8f35c7fc63884a8691d055704eb88283ae479e28))
* live nav badges for Action Requests and Now Playing ([78a8ba1](https://github.com/pentacore/media-manager/commit/78a8ba1910b57f4640a9d7c47885d4264c947075))
* live Series/Movies/Requests indexes and admin Connections ([c95b7b9](https://github.com/pentacore/media-manager/commit/c95b7b9373de50b1c64f78d740ca8f7b01875dd5))
* **metrics:** Phase 3b sparklines + per-path disk display picker ([d50a773](https://github.com/pentacore/media-manager/commit/d50a77353c1904896aaf273aa5adba181d0edd94))
* **metrics:** service_metrics table + repository + UI wiring (Phase 3a) ([9a667e2](https://github.com/pentacore/media-manager/commit/9a667e201a593c23e27b691d9494e94772ba4a59))
* **notifications:** per-user channel preferences + ServiceWarning notification ([f80d0a5](https://github.com/pentacore/media-manager/commit/f80d0a57721ca0482512c17be4fb8e094b26e6c7))
* notify admins on detected service updates ([251c6a5](https://github.com/pentacore/media-manager/commit/251c6a5fa0e04379d63ee91c95d1c46f7352c8eb))
* per-user notification channel ([2da1a39](https://github.com/pentacore/media-manager/commit/2da1a39bb61d7ff0af4239d6798d381751d2937a))
* Phase 3 — service clients, test connection, EnumUtils adoption ([bc80d3a](https://github.com/pentacore/media-manager/commit/bc80d3ad6f4a4255348e234f99de0340be7d24d5))
* Phase 4 (real-time) + Phase 5 (media UI) ([e5399a5](https://github.com/pentacore/media-manager/commit/e5399a57c68d17307f3b09958431b5f26f773fd3))
* Phase 6 — Emby monitoring ([94a022c](https://github.com/pentacore/media-manager/commit/94a022c99da66453aff5bd6a3d88cca490a5bf04))
* Phase 7 — Action Orchestration ([d542626](https://github.com/pentacore/media-manager/commit/d542626d0299992f41463deb78c3811c6400bdd2))
* Phase 8 — Health & Versions ([0adaa5e](https://github.com/pentacore/media-manager/commit/0adaa5edb4f5ab3fa4043459f40af26300e21fb5))
* Phase 9 — AI Integration ([8d76503](https://github.com/pentacore/media-manager/commit/8d765038486b9e0051f3922ec0e5b177ccde0faf))
* production Docker image (FrankenPHP + Octane) and consolidated CI ([b219301](https://github.com/pentacore/media-manager/commit/b2193010dac5d0347b065db800419748f6f4e7e4))
* **prowlarr:** /prowlarr/search page + controller ([b38d456](https://github.com/pentacore/media-manager/commit/b38d456bb32af8f901284a48814d1f01bf3ec4f6))
* **prowlarr:** add ServiceType case + factory state ([c356c25](https://github.com/pentacore/media-manager/commit/c356c2505426527ee351ce7c1f5f93b559853607))
* **prowlarr:** admin endpoint to test a configured indexer ([d0ef4b4](https://github.com/pentacore/media-manager/commit/d0ef4b4a5636291aa81ca64370a9a8e8e8b58bda))
* **prowlarr:** deferred indexer list on Service Health page ([2a38f38](https://github.com/pentacore/media-manager/commit/2a38f3841ea15889737646ee75a60235d1aa3d01))
* **prowlarr:** dispatch ProwlarrWebhookHandler from ProcessWebhookEvent ([a660fb6](https://github.com/pentacore/media-manager/commit/a660fb68a6edce3a24c4870051a88e086b14d34d))
* **prowlarr:** ProwlarrClient with search/list/test/stats methods ([830bb70](https://github.com/pentacore/media-manager/commit/830bb70dfd7c6f0878823c32dd489868179b2d3b))
* **prowlarr:** show configured indexers + test buttons on connection edit ([3992315](https://github.com/pentacore/media-manager/commit/3992315b4d52b72e4e52a46cf66c1a8fe703f45d))
* **prowlarr:** sidebar nav, smoke test, seeder, env example ([237c0dc](https://github.com/pentacore/media-manager/commit/237c0dc3553c93f22702218984c2388e038aaf72))
* **prowlarr:** webhook handler for Test/Health/HealthRestored/ApplicationUpdate ([4b3c261](https://github.com/pentacore/media-manager/commit/4b3c261447b3dc98a6cb93408621ca7b4fcca883))
* **prowlarr:** wire latest-version checks to Prowlarr/Prowlarr GitHub repo ([68f858c](https://github.com/pentacore/media-manager/commit/68f858c93faa443146ccb96a0c29579d54005f7f))
* **prowlarr:** wire ProwlarrClient into ServiceClientFactory ([2ad8778](https://github.com/pentacore/media-manager/commit/2ad8778b4ec147beb50f5a23f155d25a134b2fbd))
* realtime connection indicator in sidebar header ([ba2819f](https://github.com/pentacore/media-manager/commit/ba2819f985d73c95394878998e5f11400c174288))
* rebroadcast dashboard stats on every relevant event ([116c749](https://github.com/pentacore/media-manager/commit/116c749ca59587885bbe283c9304b7cf46f56b61))
* replace user creation with invite flow ([6771a9b](https://github.com/pentacore/media-manager/commit/6771a9b47c2d3ec05dae53b7ddbf18a17f432ded))
* **sabnzbd:** brand color + drop /sabnzbd path prefix ([e453804](https://github.com/pentacore/media-manager/commit/e4538042d4d51e27c6ca4ad1c3515c715fe92600))
* **sabnzbd:** integrate downloader + queue page ([faa5be2](https://github.com/pentacore/media-manager/commit/faa5be22d2c816a761a572db6337d617f3b4957f))
* **sabnzbd:** per-connection hidden_categories filter ([8edd22c](https://github.com/pentacore/media-manager/commit/8edd22cebaef53c91b4b900dc8b65df45dad856d))
* **sabnzbd:** webhook intake via SAB notification script ([5856479](https://github.com/pentacore/media-manager/commit/58564799d9fc4323fa689dee41da6cc50611f744))
* **search:** Phase 3c — fold Prowlarr indexer search into unified Search ([aacee0f](https://github.com/pentacore/media-manager/commit/aacee0f02b39225e771927222eaefa844976bea3))
* **seeders:** demo activity timeline + sparkline zero-guard ([99f89cf](https://github.com/pentacore/media-manager/commit/99f89cfc8c0d571f47081965c403df28e58c6615))
* **seerr:** add Completed status tab and tighten Open Emby button ([02882c4](https://github.com/pentacore/media-manager/commit/02882c4718cc9ff98caa1a840b8f80e87c430178))
* **seerr:** add Requested tab for processing requests ([d14a335](https://github.com/pentacore/media-manager/commit/d14a33586eda380b6ec3e91fbf83618d2533a72a))
* **seerr:** bulk-clear requests by status ([5a23705](https://github.com/pentacore/media-manager/commit/5a23705392e2cda68c51bdb5363daa57d458244f))
* **seerr:** edit a request's quality profile and root folder ([447b72b](https://github.com/pentacore/media-manager/commit/447b72b46621a7e7086a172f3dc45457a6b4aff1))
* **seerr:** reorder request open-in buttons + handle Failed status ([27226c9](https://github.com/pentacore/media-manager/commit/27226c978ffe4681dea2d1a77505947c0d38e31f))
* SharedUserResource and transactional ActionRequest mutations ([5f7dd29](https://github.com/pentacore/media-manager/commit/5f7dd29cd332d238e1948b82df43ac505f346245))
* show service-specific placeholders on add connection page ([579b4bb](https://github.com/pentacore/media-manager/commit/579b4bbffb302e340ae9d4ab3d2ebe43024ad6c1))
* **sidebar:** downloads badge with queued + still-in-history counts ([019ec77](https://github.com/pentacore/media-manager/commit/019ec77be9bdd8bae8db3b961bc49d2b6f6e2af6))
* surface unhealthy reason on Service Health page ([dc6947a](https://github.com/pentacore/media-manager/commit/dc6947acf4f9733ded90d895eb9192e1fc285c0c))
* **ui:** Cluster A — sidebar reorder + Emby link in Users ([7a46552](https://github.com/pentacore/media-manager/commit/7a46552ad4e9f13d8c873eb5710b61b253b7d455))
* **ui:** Phase 3d — settings + show pages + api_key_set indicator ([656d8e9](https://github.com/pentacore/media-manager/commit/656d8e904f8c24c3f83d944646818e9780281b0d))
* **ui:** phase-1 redesign — oklch tokens, mm primitives, sidebar/topbar/dashboard ([5ed1449](https://github.com/pentacore/media-manager/commit/5ed1449d60386a909b0e9ba8308fe9cd24be187d))
* **ui:** phase-2 batch 1 — re-skin Action Queue, Activity Log, Series, Movies, Requests, Search ([6784a00](https://github.com/pentacore/media-manager/commit/6784a0046c2320f556ea08d1254604c2948339e9))
* **ui:** phase-2 batch 2 — Now Playing, Watch History, Service Health, AI Chat, Admin Connections, Settings ([72c1dfb](https://github.com/pentacore/media-manager/commit/72c1dfb9d93ff4b65bded891bb99f54d861f8653))
* **ui:** phase-2 batch 3 — Admin Users, Action Rules, AI Settings, AI Usage, AI Prices ([0ed2e5a](https://github.com/pentacore/media-manager/commit/0ed2e5ad5e9a0f18cabdcf03b0b48705c7ceab7a))
* **ui:** real filters + sync + recent searches ([24d4e76](https://github.com/pentacore/media-manager/commit/24d4e762b11228d216c4386d7dd54f58ff2a56ed))
* **ui:** wire dashboard refresh + activity/health/usage/history filters ([a299e60](https://github.com/pentacore/media-manager/commit/a299e60616697eecbc849831f12763e341fafad6))
* update login page with three auth methods ([c362204](https://github.com/pentacore/media-manager/commit/c362204bceeba31d0cdd9d76b16014d8e1abd241))
* **webhook:** query-param token fallback + copyable webhook URL ([e88ee17](https://github.com/pentacore/media-manager/commit/e88ee172034bfca951b6724763bd32c81f09bead))
* **webhooks:** add admin toggle to discard captured payloads ([5dd283f](https://github.com/pentacore/media-manager/commit/5dd283f5ea893858427b9ec78c3f5ec0d625250c))
