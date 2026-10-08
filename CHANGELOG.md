# [1.36.0](https://github.com/pentacore/media-manager/compare/v1.35.2...v1.36.0) (2026-10-08)


### Bug Fixes

* **admin:** let a removed event override be added again ([906f057](https://github.com/pentacore/media-manager/commit/906f057adbd343925dab0083d75668377670ee40))
* **admin:** return 422 for malformed AI model selections ([2512575](https://github.com/pentacore/media-manager/commit/25125756224da9f8abcd7a5b579343a07c1bc7da))
* **ai:** fall back to the config title model when auto cannot resolve ([480be80](https://github.com/pentacore/media-manager/commit/480be804a2853e2403c063219df05f96936513fe))
* **ai:** forget scoped instances after each in-process browser request ([6c915d8](https://github.com/pentacore/media-manager/commit/6c915d8b6d5bcca89fb949c7f9ff4b61c41751a0))
* **ai:** keep reasoning unchanged on upgrade for providers that never got it ([dbd0889](https://github.com/pentacore/media-manager/commit/dbd0889eee03b1569dfa2635136b2e9c573cf682))
* **ai:** map failover reasoning for the provider default model ([bcd9be6](https://github.com/pentacore/media-manager/commit/bcd9be64105e1c30e573f06d45dd12fd8e2dc4d5))
* **ai:** show the default provider for legacy rows without one ([be36a06](https://github.com/pentacore/media-manager/commit/be36a06f04373fb2d741ac1c3280ac4af0c8e6a0))
* **bazarr:** name the case in the Media Advisor retry confirmation ([d0afe2e](https://github.com/pentacore/media-manager/commit/d0afe2e1b3b118e8724412bc31e9bce8ca14435b))
* **chat:** lock the model picker until the conversation's override is known ([3d3705f](https://github.com/pentacore/media-manager/commit/3d3705fb007380f489271d68be1da14e21e0486e))
* **frontend:** settle the confirm dialog on unmount and drop the duplicate description ([ba77a1c](https://github.com/pentacore/media-manager/commit/ba77a1c78d7b0a326ddc3f33d05abd5d1b5771fb))
* **pricing:** clear stale reasoning levels when a feed marks a model unsupported ([b13a9a6](https://github.com/pentacore/media-manager/commit/b13a9a6b4de3e0f7c7c9fd1374b9219a80407df9))


### Features

* **admin:** add the AI Models page ([5703af1](https://github.com/pentacore/media-manager/commit/5703af1b0b2b50f590d91184e7ec903afe70521e))
* **admin:** add the AI Models settings endpoint ([545a547](https://github.com/pentacore/media-manager/commit/545a5478a47ebccc01407fcb4a1074009f8659c9))
* **ai:** add AI task model selections table ([4015b3c](https://github.com/pentacore/media-manager/commit/4015b3c79f75e9565c9df4dcef9fbdd5092e5653))
* **ai:** migrate saved model settings to task selections ([6156a69](https://github.com/pentacore/media-manager/commit/6156a6924464b8e0d59e96b2cb6b3780eb850ec6))
* **ai:** resolve task model and reasoning selections ([fc01e26](https://github.com/pentacore/media-manager/commit/fc01e26393a14dd6d87ca50557089bcd64207844))
* **ai:** send per-task reasoning translated for each provider ([82c299c](https://github.com/pentacore/media-manager/commit/82c299c6e4e9b15166e20e8c1a6322b7168f5baa))
* **chat:** let a conversation override its model and reasoning ([64e56ec](https://github.com/pentacore/media-manager/commit/64e56ec1f0efaf64b8eb748fe2ff4ed4328f33bb))
* **chat:** let chat templates preset a model and reasoning level ([aa36194](https://github.com/pentacore/media-manager/commit/aa36194f81e53e0efdea7dcc2916dce58d679d41))
* **chat:** pick a model and reasoning level per conversation ([7d13a04](https://github.com/pentacore/media-manager/commit/7d13a04f31013bc6c2b8b13a8f9bd08fa87d5e23))
* **chat:** record the reasoning level behind each answer ([e0ab5b6](https://github.com/pentacore/media-manager/commit/e0ab5b6157f032e5cd3193dee99f376e822c5e69))
* **frontend:** ask with a shared confirm dialog on the admin pages ([df9d1f1](https://github.com/pentacore/media-manager/commit/df9d1f1a10d6041c3c8b85f24154f2ca99dbdca1))
* **frontend:** finish moving confirmations to the shared dialog and ban confirm() ([c345b4b](https://github.com/pentacore/media-manager/commit/c345b4b3c55b60b6c857a51b149f4157997777c0))
* **library:** confirm grab-queue, history and SABnzbd actions in the app dialog ([a717505](https://github.com/pentacore/media-manager/commit/a717505677b7085798bca432ebbbda9b1cbf56e5))
* **pricing:** record model reasoning capabilities from the feeds ([ab6e12f](https://github.com/pentacore/media-manager/commit/ab6e12f780b9b899f442f712a0f3868bc7f4a2df))

## [1.35.2](https://github.com/pentacore/media-manager/compare/v1.35.1...v1.35.2) (2026-10-06)


### Bug Fixes

* **actions:** say whether a payload id is missing or malformed ([8b264d7](https://github.com/pentacore/media-manager/commit/8b264d72a8ac75bbcc4a97c783eb3346b9009d7d))
* **ai-prices:** cap bulk edit/delete selections at 500 ids ([c5435a4](https://github.com/pentacore/media-manager/commit/c5435a470970de310328c515c963e2ac1afbe074))
* **ai-prices:** let a whole-catalog select-all through the bulk cap ([b4625c0](https://github.com/pentacore/media-manager/commit/b4625c028792aacc704283c990e2390e634403dc))
* **ai:** keep the price-refresh time box through a failover and report partial verifier writes ([16bd709](https://github.com/pentacore/media-manager/commit/16bd709b17070f5e88b132109be13a6e92f956de))
* **ai:** stop the verifier tally from double-counting after a late failure ([e6d9d93](https://github.com/pentacore/media-manager/commit/e6d9d9336cffb638fe2c9671ef10cad2564e8def))
* **arr:** never read a write answered with a login page as success ([a098ba2](https://github.com/pentacore/media-manager/commit/a098ba26c069bcabfda6624e894b55c98840d192))
* **audit:** audit AI price bulk edits and deletes per row ([c739854](https://github.com/pentacore/media-manager/commit/c739854ad6d91d28685cfe06d42a0c7d23380e03))
* **audit:** commit role changes, deletes and Emby unlinks with their audit row ([e3cb98b](https://github.com/pentacore/media-manager/commit/e3cb98b7f4621d45401bc979e294a9a45790acda))
* **emby:** refuse a login page in place of system info ([b244feb](https://github.com/pentacore/media-manager/commit/b244feb61a2840ce7ba43b4e9b04a4ea5aa24b61))
* **health:** store a fixed sentence for an HTTP health failure ([92ea6b8](https://github.com/pentacore/media-manager/commit/92ea6b85aba82f1fd295263efdfff417c7e6ad45))
* **jobs:** keep the anime sync and the AI price refresh inside their timeout ([7b1b303](https://github.com/pentacore/media-manager/commit/7b1b303a9d522d0d7c5d54fd7bfd05401e3742dc))
* **library:** word an unconfirmed arr write as unknown, not refused ([cfb490f](https://github.com/pentacore/media-manager/commit/cfb490fc17e0a69c0ed1ef5aaa04fc49641027cc))
* **prowlarr:** report an unconfirmed grab as unknown, not refused ([f51be13](https://github.com/pentacore/media-manager/commit/f51be13662105d302ce713101a9a43b2eded0094))
* **prowlarr:** treat a 200 that is not JSON data as an upstream failure ([725723a](https://github.com/pentacore/media-manager/commit/725723a31141aa790d6d08f418feb47488ba8153))
* **search:** catch only upstream failures in the fallback searches ([b03463d](https://github.com/pentacore/media-manager/commit/b03463db2fcb8b886789cefee87d5e2c0355db84))
* **whisparr:** retry a login-page outage like Sonarr and Radarr ([fef2105](https://github.com/pentacore/media-manager/commit/fef2105a9727677a971802a9a839cc62b212934b))

## [1.35.1](https://github.com/pentacore/media-manager/compare/v1.35.0...v1.35.1) (2026-10-06)

# [1.35.0](https://github.com/pentacore/media-manager/compare/v1.34.0...v1.35.0) (2026-10-06)


### Bug Fixes

* **anime:** judge season 0 (Specials) at series level ([6cc79c4](https://github.com/pentacore/media-manager/commit/6cc79c450147150f59c15052e9c03b8a7450e05f))
* **anime:** keep a connection in use after a per-item 4xx in the library overlay ([4dd7add](https://github.com/pentacore/media-manager/commit/4dd7add64f63b94a203210aa593e4ef8f6c63f2e))
* **anime:** keep Monitor retryable after a refusal and label requested cards ([b5f0e96](https://github.com/pentacore/media-manager/commit/b5f0e960e1a606d17864ceb665e2deb193c8226b))
* **sonarr:** bust the cache when a monitor_season search fails ([4d6e605](https://github.com/pentacore/media-manager/commit/4d6e605732fa3db00a0428cc6c8070d17c5cc5a6))


### Features

* **actions:** add monitor_season action for Sonarr ([ff8d6f5](https://github.com/pentacore/media-manager/commit/ff8d6f55c6b65dd3bc534357996dcbf565e36921))
* **anime:** flag owned seasonal entries that Sonarr or Radarr does not monitor ([ab09897](https://github.com/pentacore/media-manager/commit/ab098970c173c1ed6e0df4275bd949b293d3d77e))
* **anime:** load seasonal entries in their own deferred group ([43717bb](https://github.com/pentacore/media-manager/commit/43717bb6c68a05b622e6c79c57a5ac241f2b64e4))
* **anime:** monitor, open and external links on seasonal anime cards ([62c3f12](https://github.com/pentacore/media-manager/commit/62c3f12e0e65d3de03d7669d9d480d63a47a4121))
* **anime:** store the TVDB season of each anime mapping ([866d2c3](https://github.com/pentacore/media-manager/commit/866d2c3ec8e5463faf9812528aaef33f0da1a6fb))
* **library:** add a monitor-season library action endpoint ([81ca6a1](https://github.com/pentacore/media-manager/commit/81ca6a102215eea18a86fb74f6638329831a6be4))
* **library:** name the Seasonal Anime page in action reasons ([9c12db5](https://github.com/pentacore/media-manager/commit/9c12db57fa296e629f872b9064455d368bdbd857))


### Performance Improvements

* **anime:** look up library monitoring concurrently ([70f8562](https://github.com/pentacore/media-manager/commit/70f856220093cacd40a6f3a353c4c187ece61201))
* **arr:** stop pooled reads after a batch fails entirely on transport ([eeba13e](https://github.com/pentacore/media-manager/commit/eeba13e75f3054d93c2d6e09d0268cedf8cddf33))

# [1.34.0](https://github.com/pentacore/media-manager/compare/v1.33.1...v1.34.0) (2026-10-05)


### Bug Fixes

* **chat-templates:** drop stale ledger parts and clarify the help copy ([c3c0b32](https://github.com/pentacore/media-manager/commit/c3c0b3245283e4eb7eb6a1e849f0b91f74273a7a))


### Features

* **chat-templates:** explain templates in the editor and switch preview mode ([210e78e](https://github.com/pentacore/media-manager/commit/210e78ec9a5df9553b29ba261331f22379bb630d))
* **chat-templates:** insert variables from a ledger beside the message ([2f42ee6](https://github.com/pentacore/media-manager/commit/2f42ee67e35e9b1886b46f98c4dc5b2515050638))
* **chat-templates:** preview with example values or placeholder names ([f9d20a8](https://github.com/pentacore/media-manager/commit/f9d20a8beb282cbd89519b074356faa112e01ae7))

## [1.33.1](https://github.com/pentacore/media-manager/compare/v1.33.0...v1.33.1) (2026-10-05)


### Performance Improvements

* **replacement:** run one release search per escalation ([5bb7e8a](https://github.com/pentacore/media-manager/commit/5bb7e8a15f255d050c45daa9e5d06cba21ae6605))

# [1.33.0](https://github.com/pentacore/media-manager/compare/v1.32.1...v1.33.0) (2026-10-05)


### Bug Fixes

* **actions:** tell a missing executor id apart from an invalid one ([780d39b](https://github.com/pentacore/media-manager/commit/780d39b01ba955fafb7ba6fc24ef0f018aef2bd1))
* **ai:** bound number template defaults to the fill-time range ([311cfde](https://github.com/pentacore/media-manager/commit/311cfdeb63965681df521697132721086c849a39))
* **ai:** run the template primary action on enter and close the chat sheet on template links ([7caf939](https://github.com/pentacore/media-manager/commit/7caf93980d2a3e00e1c28e5b429412b9dd99d462))
* **ai:** stamp template last use without touching updated_at ([d655d94](https://github.com/pentacore/media-manager/commit/d655d94d006821c930bae1e4a83defc1ede7bafc))
* **ai:** start the template preview on mount instead of during setup ([04b7c50](https://github.com/pentacore/media-manager/commit/04b7c50267c634b384f3303f78d91cf2d3ee3da9))
* **ai:** surface stale-template errors and drop late renders in the fill dialog ([f2f28b0](https://github.com/pentacore/media-manager/commit/f2f28b08ea58f47f1242af363e53dbd37970eab7))
* **ai:** word a non-string decision tool argument in the tool's own terms ([aaca139](https://github.com/pentacore/media-manager/commit/aaca1399a463d8d8d33637548260a92efcbe464a))
* **connections:** use findActive() for the remaining first-active-connection lookups ([46bcffa](https://github.com/pentacore/media-manager/commit/46bcffacd285f54194fb4e53df11fe116cca5a70))


### Features

* **ai:** add chat template editor with live variables and preview ([0ec2566](https://github.com/pentacore/media-manager/commit/0ec2566bbeace31265a7509ca4690635c2975a7e))
* **ai:** add chat template model and variable types ([f8e1e43](https://github.com/pentacore/media-manager/commit/f8e1e4358399f628ae47eeda1d22fef91a34b81c))
* **ai:** add chat template options, library search, preview and render endpoints ([7b4cc5b](https://github.com/pentacore/media-manager/commit/7b4cc5b1adf8004fa4c315f7482512fd3b314bfc))
* **ai:** apply chat templates from the assistant ([069db3f](https://github.com/pentacore/media-manager/commit/069db3f7fb1653d0c180269225cb516f6651fc68))
* **ai:** cross-check chat template bodies against variable settings ([8c369d3](https://github.com/pentacore/media-manager/commit/8c369d347d3bcab3e91ff7efc59f9d1d298304d7))
* **ai:** manage chat templates ([4156075](https://github.com/pentacore/media-manager/commit/415607539d5c1642799708ddd83f3671ecc475cf))
* **ai:** parse chat template placeholders ([e159b5f](https://github.com/pentacore/media-manager/commit/e159b5f1ceb676d905a6b387528c79211f137499))
* **ai:** render chat templates against the active library ([15c37be](https://github.com/pentacore/media-manager/commit/15c37be6dbe546bb661cf7e988de928c3242ec18))


### Performance Improvements

* **actions:** read the Whisparr library list once per request when describing ([b2f5864](https://github.com/pentacore/media-manager/commit/b2f5864499611260037b7e79bc793077eb05e88a))

## [1.32.1](https://github.com/pentacore/media-manager/compare/v1.32.0...v1.32.1) (2026-10-04)


### Bug Fixes

* **actions:** retry a 200-that-isn't-JSON upstream read instead of failing it permanently ([cbcdbee](https://github.com/pentacore/media-manager/commit/cbcdbee6077590057150d0b986773c4ed21144a5))
* **ai:** sanitize stored agent failure summaries and usage error messages ([16d9b32](https://github.com/pentacore/media-manager/commit/16d9b322eeb4a58a0addcde075a9789a79a10c46))
* **arr:** treat a 200 read that is not JSON data as an outage, not an empty list ([09deb58](https://github.com/pentacore/media-manager/commit/09deb581191fb37336726552ef8c59fb1e412222))
* **audit:** record action-rule changes and AI usage price assignments ([730cb17](https://github.com/pentacore/media-manager/commit/730cb17ec28a8e4fc2745743ce8d0f703a8b5847))
* **audit:** record Emby account links and imports ([2d23255](https://github.com/pentacore/media-manager/commit/2d2325550c5c9f685996eadd5326b744d83e2562))
* **chat:** match a stored attachment name literally in the orphan sweep ([7e23de3](https://github.com/pentacore/media-manager/commit/7e23de355a52ddecac64af2f1e544c45f8fba930))
* **health:** sanitize the stored and broadcast health message ([52d002c](https://github.com/pentacore/media-manager/commit/52d002cd3c6583e21625eaed20c8b84084863174))
* **jobs:** give every queued job a timeout below the worker's and prune uploads on maintenance ([1db764d](https://github.com/pentacore/media-manager/commit/1db764dfcc1bdd472ec1ec89d2f1a73c21c619d3))
* **library:** pin Grab-queue force grab and manual import to the rows' connection ([f242778](https://github.com/pentacore/media-manager/commit/f242778d5ef938438d62a191ae6d55ec6408170b))
* **sabnzbd:** honour the queue position SABnzbd returns for a priority change ([721df9d](https://github.com/pentacore/media-manager/commit/721df9d90a6c7c9dc33a4413d3d9ee8bba474e32))
* **sabnzbd:** treat a refused or non-JSON read as a failure, not an empty queue ([ae7f7a9](https://github.com/pentacore/media-manager/commit/ae7f7a9a0aefaac75d136e1efaa154c0ff7432f2))
* **settings:** save pools, model prices and notification destinations with their audit row ([3983bcc](https://github.com/pentacore/media-manager/commit/3983bcc3d99fe4696ec5a9dc999c4165b4fed81e))
* **settings:** save settings and their audit row in one transaction ([ef6fbba](https://github.com/pentacore/media-manager/commit/ef6fbbaac134b9e7f92a5f648807c47e21ac5393))
* **subtitles:** never prune an upload row whose file is still staged ([ef4b8be](https://github.com/pentacore/media-manager/commit/ef4b8be57bb7948ca0b87dad541d20b2a5c7b100))
* **users:** audit an invite before its email and report a failed send ([cdbb9cd](https://github.com/pentacore/media-manager/commit/cdbb9cdabe1fc70f9aab3a601380a4fb4fe1879d))

# [1.32.0](https://github.com/pentacore/media-manager/compare/v1.31.3...v1.32.0) (2026-10-02)


### Bug Fixes

* **actions:** close the final-review gaps in lost-worker and stale-approved recovery ([f935657](https://github.com/pentacore/media-manager/commit/f9356576cc15d7e10885fc75401e9256251b7d42))
* **actions:** count only dispatches that acquire their lock, and stop marking never-started rows indeterminate ([6f43094](https://github.com/pentacore/media-manager/commit/6f43094e48ee1057bd142b5af1fe41b402d8a2d5))
* **actions:** fail a lost-worker re-delivery for reconciliation instead of re-running it ([21203c0](https://github.com/pentacore/media-manager/commit/21203c0f03d1e6ad0fc87215c9ce1d639a2ae9f9))
* **actions:** keep replace_media_file out of the lost-worker resume allow-list and log/record the cause in failed() ([f31d0d9](https://github.com/pentacore/media-manager/commit/f31d0d9204d6bc2cf6193286e32a271a5bf87a06))
* **actions:** let programming errors surface from the activity observer ([aa79cb0](https://github.com/pentacore/media-manager/commit/aa79cb0af716965a6d4f486642cc4d9d5c06e7d7))
* **actions:** re-dispatch approved requests whose execution job was lost ([9f81853](https://github.com/pentacore/media-manager/commit/9f818536c55a34711551c6f340dbeda2178683f0))
* **actions:** write action activity rows and broadcasts only after commit ([428bc0d](https://github.com/pentacore/media-manager/commit/428bc0d9b6c047d6cd2994d94c8e94f82a646748))
* **arr:** read Radarr and Whisparr items uncached before an executor PUTs them back ([7311419](https://github.com/pentacore/media-manager/commit/7311419865d9f701091c9e68ebbdf23f07eb56ec))
* **emby:** fold or dispatch a manual library refresh under one lock ([d66f4d4](https://github.com/pentacore/media-manager/commit/d66f4d4cdf7fa1ef457d58664e8a3eaa6c19f5f8))
* **library:** file a delete and its audit row in one transaction ([11576df](https://github.com/pentacore/media-manager/commit/11576df024e599baacc68414af77a9e7195aa75f))
* **library:** pin Grab-queue removal to the connection its rows came from ([7e279c5](https://github.com/pentacore/media-manager/commit/7e279c5adb969bc8635aeacad97774be7d753919))
* **library:** refuse episode requests whose episodes are not the series' ([1de2b97](https://github.com/pentacore/media-manager/commit/1de2b977285ebc77933ae44315d31088d3536f97))
* **library:** reset the Grab-queue selection when the rendered connection changes ([1a64ac5](https://github.com/pentacore/media-manager/commit/1a64ac5ad78aeb7e4309a963690a857a3494209b))
* **media:** pin single series and movie deletes to the page's connection ([2b7cd56](https://github.com/pentacore/media-manager/commit/2b7cd562d75940903ab76557c2366fc8826cf853))
* **schedule:** assert the Bazarr schedule's cron expression directly ([bcb538b](https://github.com/pentacore/media-manager/commit/bcb538bc311160af5009ca0c8b35457cc1478307))
* **sonarr:** read the series uncached before an executor PUTs it back ([6163478](https://github.com/pentacore/media-manager/commit/616347866078e434ede56cbde27652d7ae1c1a22))
* **sonarr:** refuse episode ids that are not episodes of the request's series ([7d6bc60](https://github.com/pentacore/media-manager/commit/7d6bc60cd35bf82bc19a00b45eedb2d1e8ffc471))


### Features

* **actions:** mark a deliberate transient retry on the executing request ([91c042c](https://github.com/pentacore/media-manager/commit/91c042cda10b355f38a42afb883ab42a762fd8a5))
* **emby:** log each trigger folded into a waiting library scan ([3c250b1](https://github.com/pentacore/media-manager/commit/3c250b129fce543534326f1ba670fe466b7b8523))

## [1.31.3](https://github.com/pentacore/media-manager/compare/v1.31.2...v1.31.3) (2026-10-02)


### Bug Fixes

* **actions:** keep the abort message when a pinned connection was deleted ([b127af8](https://github.com/pentacore/media-manager/commit/b127af8493f1de684734d7981a086827d3aea195))
* **actions:** store sanitized failure messages on action requests ([a1ee8bb](https://github.com/pentacore/media-manager/commit/a1ee8bb68c2e0f9c9c0d310df7274d0a9b14bb5e))
* **admin:** sanitize upstream errors in connection and indexer tests ([9c03bbe](https://github.com/pentacore/media-manager/commit/9c03bbe56bf80dbfb15755b188718c54229ccf6b))
* **auth:** gate activity log, statistics and service health behind manage-library ([e4b40bf](https://github.com/pentacore/media-manager/commit/e4b40bfc0bf423f45bc9793ec94201ca5d51f33b))
* **auth:** gate every subtitle page behind manage-library ([0e2440b](https://github.com/pentacore/media-manager/commit/0e2440ba396f227247297be0d61cc151a58d257f))
* **auth:** limit a viewer's dashboard feed to their own activity ([ac79877](https://github.com/pentacore/media-manager/commit/ac798777146927e4c6edfc9cefc29488ea756fba))
* **errors:** keep URL hosts and redact Windows paths in upstream error text ([fc78544](https://github.com/pentacore/media-manager/commit/fc78544c608d43a58066d7359c35fdb136d8b5e2))
* **library:** only mark history rows failed that were rendered as grabs ([2b60f1c](https://github.com/pentacore/media-manager/commit/2b60f1cfceb2178dc53cfed75d9c6232458bf4cc))
* **library:** stop echoing raw arr errors and paths on the grab queue ([27fb5c8](https://github.com/pentacore/media-manager/commit/27fb5c88c86e9b5393325b4a6d4729496d10a792))
* **radarr:** show an outage instead of an empty library on movie pages ([b6f698a](https://github.com/pentacore/media-manager/commit/b6f698a5ba0d78d07dd9b8ac39bc2b4219bf48d5))
* **seerr:** keep the request lock for a cancel's worst case ([6d47a65](https://github.com/pentacore/media-manager/commit/6d47a65de46921b154a513e3888a7b61dc409d87))
* **seerr:** sanitize upstream error text in request and discover responses ([3f038de](https://github.com/pentacore/media-manager/commit/3f038defb23f5b61df02ad3ca8fa9a3d4f865380))
* **seerr:** serialise request cancel with approvals and never retry a delete ([c05cd0c](https://github.com/pentacore/media-manager/commit/c05cd0cd36381dc980fe300259138402b6eb8daf))
* **sonarr:** show an outage instead of an empty library on series pages ([7f963ba](https://github.com/pentacore/media-manager/commit/7f963ba1c179de91416fa0ece0171177225d52de))
* **webhooks:** drop the stale Bazarr match.unhandled baseline entries ([3e113ca](https://github.com/pentacore/media-manager/commit/3e113ca02eef50f0df410ff0f14ae303bc0c9756))
* **webhooks:** return 404 for Bazarr on the generic webhook endpoint ([2f5e380](https://github.com/pentacore/media-manager/commit/2f5e380be8eaa00c6f2ca7d8e442c7e09b217b7d))

## [1.31.2](https://github.com/pentacore/media-manager/compare/v1.31.1...v1.31.2) (2026-10-02)


### Bug Fixes

* **auth:** render the two-factor challenge page ([2ef31d0](https://github.com/pentacore/media-manager/commit/2ef31d049960351f4b8d0d1614cb20f633a13a40))
* **phpstan:** clear findings from phpstan 2.2.16 ([314d67a](https://github.com/pentacore/media-manager/commit/314d67ae39e9da32f2f6ac4e2eb3a7a031c2432f))

## [1.31.1](https://github.com/pentacore/media-manager/compare/v1.31.0...v1.31.1) (2026-10-01)


### Bug Fixes

* **bazarr:** retry capability discovery instead of disabling every operation ([453430c](https://github.com/pentacore/media-manager/commit/453430c869fe1ffec79b3b15968aa856030adab7))

# [1.31.0](https://github.com/pentacore/media-manager/compare/v1.30.1...v1.31.0) (2026-10-01)


### Bug Fixes

* **actions:** keep long reject reasons from breaking the activity log ([c0ea5dd](https://github.com/pentacore/media-manager/commit/c0ea5dd633eb18a59fc527406f6e73384750fa9b))
* **bulk:** bound bulk runs by a time budget and stop retrying an unreachable upstream ([0d8854e](https://github.com/pentacore/media-manager/commit/0d8854e46935f989dc7ae3742c5161cf7f768bd9))
* **bulk:** freeze the selection while a run is in flight and keep bulk UI honest ([fab7f12](https://github.com/pentacore/media-manager/commit/fab7f126dc3765284af3f881bb6ca73108ffe9ec))
* **bulk:** keep the time budget hard and cut descriptions by character ([7eacfa1](https://github.com/pentacore/media-manager/commit/7eacfa1221380a1abff6bc0b10f8afa571768c3e))
* **library:** address grab-queue bulk review findings ([2294b30](https://github.com/pentacore/media-manager/commit/2294b30a5c5812047a2835d99ca5a548efc3491d))
* **sabnzbd:** close review gaps in the bulk slot actions ([2e136a5](https://github.com/pentacore/media-manager/commit/2e136a510ad5fc483466a35a4aee97b8c4f87f08))
* **whisparr:** describe Whisparr actions only on their pinned connection ([ed6a376](https://github.com/pentacore/media-manager/commit/ed6a376e86b5dfcd61772daa33b880b65ad19314))
* **whisparr:** treat non-JSON reads and profile outages as errors, expose rejection reasons ([5b05302](https://github.com/pentacore/media-manager/commit/5b05302582b0de815fa345a080352fa0d64027b4))


### Features

* **actions:** add a bulk runner that fans out through the single-item path ([587dd00](https://github.com/pentacore/media-manager/commit/587dd003a2e8c1b4e0131f1234534f1769d06672))
* **actions:** approve or reject Action Queue requests in bulk ([59573f6](https://github.com/pentacore/media-manager/commit/59573f6166005388838398575318d8b61cd3ad87))
* **ai:** look up add options and start indexer searches from chat ([7a32754](https://github.com/pentacore/media-manager/commit/7a327548cfc43b990dcd3fb224322df4107e661f))
* **library:** add the Sonarr and Radarr bulk action endpoint ([cf1d139](https://github.com/pentacore/media-manager/commit/cf1d1392eb252a10064b5e25b1a589d6b62e3cd2))
* **library:** remove or blocklist many Grab-queue items per service ([efb843a](https://github.com/pentacore/media-manager/commit/efb843a5c8073e8c217de483bfe20563e40e2fe5))
* **library:** select series and movies and act on them in bulk ([cd685d0](https://github.com/pentacore/media-manager/commit/cd685d07a1d3cf979c7089eedc18ce002f0503fd))
* **sabnzbd:** pause, resume and delete many queue slots at once ([10bc7fc](https://github.com/pentacore/media-manager/commit/10bc7fcacdd4a39e4fdb2bc3744d683cb610f3d2))
* **settings:** add a per-user Whisparr poster blur and a blur-capable poster ([72e593b](https://github.com/pentacore/media-manager/commit/72e593b34eff27d053f2eaa5eca4eac501ec4d03))
* **whisparr:** add the admin-only Whisparr library and title pages ([8cdf377](https://github.com/pentacore/media-manager/commit/8cdf3773113b15a9fb09d2b8609f213c9ba9f50f))
* **whisparr:** add the pinned whisparr_search action type ([57858e9](https://github.com/pentacore/media-manager/commit/57858e9a5699e1f70379463f71befdbcc5f35a37))
* **whisparr:** bulk monitor, re-profile, search and delete from the library ([a4f0ea6](https://github.com/pentacore/media-manager/commit/a4f0ea68b0567932bc23d21b832b99e6f7d7bf73))
* **whisparr:** monitor, re-profile, search and delete from the title page ([8e885c5](https://github.com/pentacore/media-manager/commit/8e885c5086ce3234e1cdb0d54031dbb27741fa00))
* **whisparr:** present v2 sites and v3 movies as one row shape ([530bd96](https://github.com/pentacore/media-manager/commit/530bd96381e74aca91c4bcc64e571c3951eccb1d))

## [1.30.1](https://github.com/pentacore/media-manager/compare/v1.30.0...v1.30.1) (2026-10-01)


### Bug Fixes

* **admin:** keep long catalog model ids inside the picker dialogs ([2a840d4](https://github.com/pentacore/media-manager/commit/2a840d434d82bfc210a9a78d147a79e876c035ef))

# [1.30.0](https://github.com/pentacore/media-manager/compare/v1.29.0...v1.30.0) (2026-10-01)


### Bug Fixes

* **activity-log:** clear the new audit rows counter when a filter changes ([3f0eedc](https://github.com/pentacore/media-manager/commit/3f0eedcf6e182f0d166d94caab49a001125f3cfa))
* **ai-prices:** audit price rows created by a catalog bulk add ([03896fd](https://github.com/pentacore/media-manager/commit/03896fde0a86035875e7185ae12f6ea69fa7eb35))
* **audit:** audit Bazarr connection saves that only change the mapping ([36a1ee0](https://github.com/pentacore/media-manager/commit/36a1ee070fc32155b1c9d08bb189857aec8a7e0d))
* **audit:** record queue removals against their Sonarr or Radarr connection ([6a0b0c6](https://github.com/pentacore/media-manager/commit/6a0b0c6984236da49855895c125d9240c0db6318))
* **downloads:** surface poll outages, guard history paging and retry double-posts ([470fea9](https://github.com/pentacore/media-manager/commit/470fea9d49834f4c9ef918390aff2e8824f2ec58))
* **emby:** treat a non-list user answer as an error and tolerate bad dates ([9c2d8be](https://github.com/pentacore/media-manager/commit/9c2d8bea5abdae0b9bb95ae5ac75f465bda55142))
* **library:** scope the history title assertion instead of reshaping the row ([089c9e3](https://github.com/pentacore/media-manager/commit/089c9e3d7be3a1f4c10842076b7ff69920579b9a))
* **prowlarr:** key indexer search rows by indexer and release ([89161bf](https://github.com/pentacore/media-manager/commit/89161bf8bb388fa7d586e2f01a91b4c892afbc3c))
* **prowlarr:** redact indexer info urls and flag lost grab responses ([f809adb](https://github.com/pentacore/media-manager/commit/f809adbecff6609e57bc5c1b542e6de5674cb296))
* **sabnzbd:** honour status false on queue pause, resume and delete ([0d4120d](https://github.com/pentacore/media-manager/commit/0d4120dd8eba4e4f29f3caaca2a3ff31821ed48f))
* **sabnzbd:** strip filesystem paths from history failure text ([f214e3f](https://github.com/pentacore/media-manager/commit/f214e3fb9ace9c10005b6f22f94501e39266a4b3))
* **search:** drop only secret query parameters from Prowlarr info links ([5b7d95b](https://github.com/pentacore/media-manager/commit/5b7d95b064b066c841f9d74faa3ea1ac564ea2ba))


### Features

* **audit:** add an admin-only audit category to activity logs with its own retention ([f968641](https://github.com/pentacore/media-manager/commit/f968641d58117bda3859fa68f9568a4227cc90d2))
* **audit:** add the Audit category filter and masked change details to the Activity log ([a1aa484](https://github.com/pentacore/media-manager/commit/a1aa48458f1fd10d95d6181989900be45ae31e05))
* **audit:** add the AuditLogger with secret masking and settings snapshots ([68f471e](https://github.com/pentacore/media-manager/commit/68f471e9da6d32a3f6919ea84100cf67b40e9974))
* **audit:** keep the actor's name on audit rows after account deletion ([fe84774](https://github.com/pentacore/media-manager/commit/fe84774a99fb3f10fef49f02b5971d3c89d6c974))
* **audit:** record admin settings saves and AI pricing changes with masked diffs ([910ff52](https://github.com/pentacore/media-manager/commit/910ff522c07b96246a44e0ef6315648cfc98e0fd))
* **audit:** record user, invite, connection, Emby unlink and requested delete changes ([b5b554d](https://github.com/pentacore/media-manager/commit/b5b554dbd81e126f1622e1fa94f534685adaa628))
* **audit:** show audit rows to admins only across the log, export, dashboard, AI tools and broadcasts ([86a36a2](https://github.com/pentacore/media-manager/commit/86a36a250d1606b57026d8ed3aabe985a2d1ed44))
* **emby:** add an admin library refresh and owner-checked played state toggles ([d74387e](https://github.com/pentacore/media-manager/commit/d74387e2d4206d167f2fa1af60e9b88e96dccb5a))
* **emby:** add library refresh, played toggles and the Emby users list to the Emby pages ([85a2fd4](https://github.com/pentacore/media-manager/commit/85a2fd43a9019d0ced17f1c06563672095e4bc35))
* **emby:** list Emby users on User Links and let admins link from the list ([038f6ec](https://github.com/pentacore/media-manager/commit/038f6ec1d985f6e345d0fa01606e79ef4069508e))
* **library:** add Sonarr and Radarr history tabs with paging and mark failed ([6d68fcb](https://github.com/pentacore/media-manager/commit/6d68fcb8be00bf27faa86a2d9e57f4c981024e56))
* **library:** page Sonarr and Radarr history per service and let admins mark grabs failed ([3efc4aa](https://github.com/pentacore/media-manager/commit/3efc4aac5bca4f9ae6b6e4880aa9581d3cfd9fe4))
* **prowlarr:** link the indexer search in the nav and let admins grab releases ([0a168ff](https://github.com/pentacore/media-manager/commit/0a168ff641e663ec19fd79e66c20c49743624300))
* **sabnzbd:** add speed limit, paged history, retry and delete for admins ([74feeae](https://github.com/pentacore/media-manager/commit/74feeaeb52414fa9bc7ed5d990e456c120a159be))
* **sabnzbd:** add the speed limit control and a paged history with retry and delete ([1ff5f01](https://github.com/pentacore/media-manager/commit/1ff5f01d73abb51193b6c2548a1f1e671c268afe))

# [1.29.0](https://github.com/pentacore/media-manager/compare/v1.28.0...v1.29.0) (2026-10-01)


### Features

* **admin:** bulk edit and delete AI model prices ([17b1a47](https://github.com/pentacore/media-manager/commit/17b1a47da3046a596f8ec8089d0be85bebb0f603))

# [1.28.0](https://github.com/pentacore/media-manager/compare/v1.27.0...v1.28.0) (2026-10-01)


### Bug Fixes

* **admin:** explain a stale catalog pick saved as manual ([03bc3f0](https://github.com/pentacore/media-manager/commit/03bc3f0f04214a066df13d36ac6a6a2472fceaf9))
* **admin:** keep bulk catalog picks when the catalog is down ([0236cca](https://github.com/pentacore/media-manager/commit/0236cca23a58c7109139a6cae1806e2742a99579))
* **admin:** reload the catalog list each time the bulk dialog opens ([ed25342](https://github.com/pentacore/media-manager/commit/ed25342825158d270e0c3c5d2b16deffe51b677a))
* **admin:** tidy the catalog picker and add price form ([9f6ebc9](https://github.com/pentacore/media-manager/commit/9f6ebc9c22b75419800b34e12759ca2a7ee6183b))
* **ai-prices:** link the uncovered-provider message to AI settings ([0d3043c](https://github.com/pentacore/media-manager/commit/0d3043cdf6805e36ee26d369a08076e11018ce93))
* **pricing:** fingerprint the catalog cache key with the xai key state ([171bbb5](https://github.com/pentacore/media-manager/commit/171bbb5361549623eaa28451da8bcef3b935654c))
* **pricing:** load the catalog picker slice safely ([6968dec](https://github.com/pentacore/media-manager/commit/6968decc07f298f67573b2958336c42954fa2690))
* **pricing:** only count feeds that could cover a provider as an outage ([d928f6a](https://github.com/pentacore/media-manager/commit/d928f6a86fcf4ae1de8dfca3790b2486869ed779))


### Features

* **admin:** add models in bulk from the pricing catalog ([9644d19](https://github.com/pentacore/media-manager/commit/9644d19651f70d4d836bd6b27528fbccf0be0e8d))
* **admin:** bulk add picked catalog models as synced prices ([fa89ee2](https://github.com/pentacore/media-manager/commit/fa89ee25bca7f808149e9fc80103322830861f69))
* **admin:** keep feed provenance for an unedited catalog pick ([6336753](https://github.com/pentacore/media-manager/commit/6336753cb921ba8a4e6d0d709b7f79e8f549a8bc))
* **admin:** pick a catalog model inside the add price form ([6933e60](https://github.com/pentacore/media-manager/commit/6933e60bdfa96ab78694d72f921f726c72791334))
* **admin:** serve addable catalog models per provider ([d9b9f9a](https://github.com/pentacore/media-manager/commit/d9b9f9a47e76233364756dcc6bc1a710e664dd43))
* **pricing:** browse addable catalog models per provider ([cbdc288](https://github.com/pentacore/media-manager/commit/cbdc288445e19a023d1f6d78bcc8520b3c3d8aa1))
* **pricing:** let an admin's explicit catalog pick create rows ([611f7ac](https://github.com/pentacore/media-manager/commit/611f7acec0d97ea6d8fb6cd803e7fe871de867d9))

# [1.27.0](https://github.com/pentacore/media-manager/compare/v1.26.0...v1.27.0) (2026-09-30)


### Bug Fixes

* **actions:** describe the new arr actions only on their pinned connection ([68daa05](https://github.com/pentacore/media-manager/commit/68daa053b26cc2ff68881278c1a1f90bf8d1ba58))
* **actions:** keep the grab release guid out of the browser and the executor result ([a02d9ab](https://github.com/pentacore/media-manager/commit/a02d9ab98ba5d091fcce3b39368b8cdd32482c01))
* **actions:** refuse unpinned or mismatched connections for the new arr executors ([f99be69](https://github.com/pentacore/media-manager/commit/f99be69fd8d7af900378226558da4fd7da838dda))
* **anime:** file anime requests only as a validated Seerr user ([6610de0](https://github.com/pentacore/media-manager/commit/6610de0ed52a84dea2f3b8f09f37f16169d96680))
* **calendar:** drop malformed calendar and wanted entries at the client ([bb85a0f](https://github.com/pentacore/media-manager/commit/bb85a0ffe5e12975c7e7be56bb6b7cf599361fc8))
* **calendar:** toggle an episode's monitoring by its own flag ([6a9c733](https://github.com/pentacore/media-manager/commit/6a9c733c6413c472c9331991a26162baca17f364))
* **calendar:** UTC-bucket movie dates, non-retrying reads, wanted paging ([38fc664](https://github.com/pentacore/media-manager/commit/38fc664ddd99d01373912b78441af5160be8bb2f))
* **dashboard:** hide the Webhooks · 24h card from viewers ([11a3247](https://github.com/pentacore/media-manager/commit/11a32470807a187fa8d9d5d69f8719b74ab457d7))
* **dashboard:** keep approvals, webhook events and service versions from viewers ([1adcb8e](https://github.com/pentacore/media-manager/commit/1adcb8e4ab81ce22fabb76be21f137b09b73010d))
* **discover:** stop defaulting an unmatched chooser to the first Seerr user ([806415f](https://github.com/pentacore/media-manager/commit/806415fb15dfcc0a060b96c7eca4c929a19839bf))
* **library-actions:** rate-limit release search and close test gaps ([42c15b1](https://github.com/pentacore/media-manager/commit/42c15b13f1a174fd3fac7b304a3f9ab8531d8033))
* **library:** hide empty-season controls and show queued grabs as info ([dcbce11](https://github.com/pentacore/media-manager/commit/dcbce1112d8fbca1120399a3910f1955e14183ec))
* **library:** keep release guids server-side and bind grabs to the searched title ([5d1262f](https://github.com/pentacore/media-manager/commit/5d1262f5cc8e7362c098045c9030d51ee3273952))
* **library:** stop viewers triggering quality profile and badge upstream calls ([cdfca13](https://github.com/pentacore/media-manager/commit/cdfca13e90ecc145dc71c7dcb2b4d26e45937ac1))
* **search:** send viewers only the Seerr external url ([35fcc5a](https://github.com/pentacore/media-manager/commit/35fcc5aadf8d11ec5d4a239f2879bf0df5e6753e))
* **seerr:** keep a partial match on a failed page walk and add cache/edge tests ([70f0759](https://github.com/pentacore/media-manager/commit/70f07592797b438904c6cebd1d32882011c617a6))
* **seerr:** make TitleDetailSheet's manual user pick reset deterministic ([e56f66f](https://github.com/pentacore/media-manager/commit/e56f66f499e4f68be42e5391d794c466b2c02a3d))
* **seerr:** tighten Seerr identity resolution, caching and UX ([2e3940f](https://github.com/pentacore/media-manager/commit/2e3940f3cb1f39ea24ccf5e3426c8bfe2a5771de))
* **series:** derive the season monitor toggle from its episodes ([b0d89a6](https://github.com/pentacore/media-manager/commit/b0d89a67d1d465feb245709a620898cca46a7cad))
* **tests:** split viewer/member visit into separate browser tests ([26edeb8](https://github.com/pentacore/media-manager/commit/26edeb8d603f3bfe17058e21d3676d42b26a554a))
* **wanted:** refresh the wanted badge on a schedule and recompute inline once under a lock ([73a7003](https://github.com/pentacore/media-manager/commit/73a70034648475806746f3bde15cbe780b0ab414))


### Features

* **actions:** add episode monitoring, indexer search and release grab executors ([0a361ce](https://github.com/pentacore/media-manager/commit/0a361ce9537f80cf66dafe28d2dc993867fd23a9))
* **actions:** describe episode monitoring, searches and release grabs ([5beae45](https://github.com/pentacore/media-manager/commit/5beae451bd5e3029a48b845ff5707a92329bf2fd))
* **auth:** define role-derived abilities and share them as auth.can ([b506f14](https://github.com/pentacore/media-manager/commit/b506f1439b905147a4525f801a1e00a5dea249d5))
* **auth:** open library reads and Seerr search to viewers via abilities ([c82e09e](https://github.com/pentacore/media-manager/commit/c82e09e3e7c9088b7bbf7b2c8498cd00866a76d6))
* **calendar:** add the household calendar with month and agenda views ([7dd9042](https://github.com/pentacore/media-manager/commit/7dd9042bbe72c6f0d0666dabfc725a587d75f06b))
* **calendar:** read Sonarr/Radarr calendars and wanted lists and merge the calendar ([e3bbbfe](https://github.com/pentacore/media-manager/commit/e3bbbfe60444fcbec5821023cb2c426e080cf1cb))
* **discover:** add the Discover page with a title detail sheet and season picker ([de939ac](https://github.com/pentacore/media-manager/commit/de939acf32935d6a0185989424b1127edbc81ec8))
* **discover:** serve Seerr discover rows and file requests as the resolved user ([abd3840](https://github.com/pentacore/media-manager/commit/abd38404efef608c6aff6df720ededc8c200bc85))
* **library:** add monitor, quality profile, search and interactive grab controls ([0fe4eb1](https://github.com/pentacore/media-manager/commit/0fe4eb1977b4033aec86f3d190c04b8bdb65d080))
* **library:** route member library actions through the Action Queue ([1bbebc1](https://github.com/pentacore/media-manager/commit/1bbebc1de1adecc9aa2da2b7a49af111577a14df))
* **nav:** filter navigation and library controls by ability ([e7cb95b](https://github.com/pentacore/media-manager/commit/e7cb95bda9f1cc1b86e22c43aad8d5ac3dd4b216))
* **requests:** add My requests with cancel for your own pending requests ([a86b63c](https://github.com/pentacore/media-manager/commit/a86b63c8252f665255c302dae6e04a7222029d0d))
* **search:** list every Seerr title and request it from the detail sheet ([857ed05](https://github.com/pentacore/media-manager/commit/857ed05945b771d44d9ce7579be1e6a34cbca63c))
* **seerr:** add trending, upcoming and by-user reads plus a title presenter ([f22d314](https://github.com/pentacore/media-manager/commit/f22d3141d22bfe35b4e71139f075fb313a9d683d))
* **seerr:** resolve a user's Seerr account from their Emby link or email ([42ff6f8](https://github.com/pentacore/media-manager/commit/42ff6f8326bcc45a6d96b4d324da0ac41182111f))
* **wanted:** add the Wanted page with per-item and bulk searches and a missing badge ([3a87a42](https://github.com/pentacore/media-manager/commit/3a87a42546684f118620ec1980fb0bb06289f9ab))

# [1.26.0](https://github.com/pentacore/media-manager/compare/v1.25.0...v1.26.0) (2026-09-29)


### Bug Fixes

* **admin:** clear the stale failover model on a provider change and fix embeddings labels ([c7b54eb](https://github.com/pentacore/media-manager/commit/c7b54eb62854ca4634ed6052fa8bd6126813238c))
* **admin:** keep model select trigger ids and option labels for browser selectors ([5033b68](https://github.com/pentacore/media-manager/commit/5033b68319c9e493da89ac5475a3dc7efc08da32))
* **admin:** keep the OpenRouter picker open when an import fails ([a914209](https://github.com/pentacore/media-manager/commit/a91420977ace4a6f28dc76150d24786ea57f1801))
* **ai:** give each re-embed chain its own running-flag token ([861b577](https://github.com/pentacore/media-manager/commit/861b577dd3d17876ed14fc8737112ee288927ed7))
* **ai:** guard the library re-embed against parallel chains ([50bc631](https://github.com/pentacore/media-manager/commit/50bc6317aea94867e449ed2ddeb6252394c020ec))
* **ai:** let "OpenRouter default" sort override a configured env sort ([22a7475](https://github.com/pentacore/media-manager/commit/22a74755171d18b86c0b3b4a19a2d6977ec11efd))
* **ai:** send OpenRouter routing preferences on classify and rerank calls ([d62b5ad](https://github.com/pentacore/media-manager/commit/d62b5ad71aeb05c7ff149a889af11046be5393b2))
* **ai:** validate OpenRouter upstream provider slugs ([ef45879](https://github.com/pentacore/media-manager/commit/ef45879945cf51e49859f5f3754eb0f25ce2b5b7))
* **jobs:** expire the re-embed page lock and cover its dedup ([b87c757](https://github.com/pentacore/media-manager/commit/b87c757f6bc7a2609737ddbd4cc171ee88fda467))
* **jobs:** stamp the re-embed signature it ran with, not the one at completion ([44bf391](https://github.com/pentacore/media-manager/commit/44bf391f56173799520c66bd9750aa7e0786d086))
* **pricing:** count a blank embeddings model's provider default as in use ([e249c7f](https://github.com/pentacore/media-manager/commit/e249c7ff59fe5e31c94a5916970bad64fc5e5cee))
* **prowlarr:** stop sending release download links and guids to the browser ([5fe27cf](https://github.com/pentacore/media-manager/commit/5fe27cfec2afc31859d01be964c6233a00585bb2))
* **search:** treat wrong-dimensioned embedding vectors as failed ([d5f9554](https://github.com/pentacore/media-manager/commit/d5f9554eeed5958a3a0aa7776544484f4dddbc49))


### Features

* **admin:** add OpenRouter models from a searchable picker ([7cb4069](https://github.com/pentacore/media-manager/commit/7cb406903a337b286f7404af3c203d0414533461))
* **admin:** choose the embeddings model and re-embed the library on demand ([1b87085](https://github.com/pentacore/media-manager/commit/1b8708541005aee751aabfd18cf1d91727304776))
* **admin:** pick provider and model together and edit OpenRouter routing ([38c5eac](https://github.com/pentacore/media-manager/commit/38c5eacbc0a2d31bd39e0af196c06344c956aee5))
* **admin:** save provider selections and OpenRouter routing preferences ([bc4148f](https://github.com/pentacore/media-manager/commit/bc4148fe49eeb807af6e4e697ca7f81d7e7809bc))
* **ai:** run each agent on its own provider and model selection ([7ba429c](https://github.com/pentacore/media-manager/commit/7ba429ca5a29ce8381065d89edec38f7d6141e72))
* **ai:** send reasoning effort and routing preferences to OpenRouter ([253d931](https://github.com/pentacore/media-manager/commit/253d931e7289a72db401e27c67255c1a3d26e3dd))
* **ai:** store a provider alongside each model setting ([1138958](https://github.com/pentacore/media-manager/commit/1138958c46c02f289bc9da4efe954400843732b5))
* **pricing:** import selected OpenRouter models into the catalog ([b6806b7](https://github.com/pentacore/media-manager/commit/b6806b715e86264a9636364c3aa3dee8cb01b548))
* **search:** embed the library with an admin-selected provider and model ([2a9a5a5](https://github.com/pentacore/media-manager/commit/2a9a5a54df437d8187f0babee38e2784a90993c2))

# [1.25.0](https://github.com/pentacore/media-manager/compare/v1.24.0...v1.25.0) (2026-09-28)


### Bug Fixes

* **bazarr:** narrow the decider's inspection catch and surface tool rejection reasons ([a574796](https://github.com/pentacore/media-manager/commit/a57479691d7b0ef41e26f36770a12824f436f318))
* **chat:** poll the client with a flush at each step boundary before stopping ([f05bdd8](https://github.com/pentacore/media-manager/commit/f05bdd8948f48fad941241d14045113778722977))
* **chat:** stop a disconnected stream at its next step while still billing it ([b100790](https://github.com/pentacore/media-manager/commit/b10079009aba43815fc16c0c364977b6eaaab43d))
* **decision-agent:** record a failed decision when the worker stops a run ([aea0c1d](https://github.com/pentacore/media-manager/commit/aea0c1d0fa79c7e38097db769f38018eb0ef0972))
* **types:** declare the Advisor projection's exceptions and type the feed phase's providers ([9a78c64](https://github.com/pentacore/media-manager/commit/9a78c64719c2f6afd56c97a11730b79863f34d48))


### Features

* **chat:** add a stop button that aborts the streaming reply ([5bbdd7a](https://github.com/pentacore/media-manager/commit/5bbdd7aac62b469e1669bc54b5065d55b03e7442))

# [1.24.0](https://github.com/pentacore/media-manager/compare/v1.23.0...v1.24.0) (2026-09-28)


### Bug Fixes

* **chat:** group sweepOrphanedAttachments' OR branches before chunkById ([bd3382c](https://github.com/pentacore/media-manager/commit/bd3382cefec0bcf9267a490364c2fcbc3269c130))
* **metrics:** guard the pre-existing gauges against dependency failures ([daee0aa](https://github.com/pentacore/media-manager/commit/daee0aad93ee1d7a3e2a2322de671008c17f9bd4))
* **metrics:** isolate gauge failures, bound failed-jobs cardinality, index webhook lag query ([f846c3a](https://github.com/pentacore/media-manager/commit/f846c3a8c6c179a646e77130fab1816e26bc5200))
* **schedule:** give the ops heartbeat an overlap lock expiry ([5e811ad](https://github.com/pentacore/media-manager/commit/5e811adf4471c1e5a5ea9ca2bdcfb2ae70bdd1db))
* **scheduler:** size overlap locks to each task and clear stale locks at boot ([cf1747f](https://github.com/pentacore/media-manager/commit/cf1747fc2c8fe007104e50a4799f7a2d85ba0d6b))


### Features

* **health:** probe db and valkey on /up and check workers by heartbeat ([84f0f72](https://github.com/pentacore/media-manager/commit/84f0f72995e492e255fc5aab4cb995f6d37178fe))
* **metrics:** export failed jobs, queue backlog, heartbeat age and webhook lag ([8df0f19](https://github.com/pentacore/media-manager/commit/8df0f195d5b6fa703de7f4013c33044dbe5e6043))
* **queue:** route jobs onto named lanes with a dedicated ai worker ([45f771b](https://github.com/pentacore/media-manager/commit/45f771b180f81cd75716854104fb716d8c4500ac))
* **queue:** run long maintenance jobs on a maintenance lane drained by the queue-ai worker ([e93e897](https://github.com/pentacore/media-manager/commit/e93e897f8331bead0d6d697d5c138b92a57fb708))
* **retention:** opt-in conversation pruning and an orphaned chat attachment sweep ([e397f71](https://github.com/pentacore/media-manager/commit/e397f71704ee2f3e26e2418e5ad905f28cc1c139))
* **retention:** prune terminal action requests, subtitle cases, price runs, failed jobs and batches ([b594b20](https://github.com/pentacore/media-manager/commit/b594b20fc5c527eef8eedfdd77ef19b4b1abd91b))

# [1.23.0](https://github.com/pentacore/media-manager/compare/v1.22.0...v1.23.0) (2026-09-28)


### Bug Fixes

* **actions:** abort pinned actions whose connection was deactivated ([53130fb](https://github.com/pentacore/media-manager/commit/53130fb6a5c0463dc45df9ceb6fad40e5b20be49))
* **actions:** log the executing claim and worker_lost failures in the audit trail ([1238b55](https://github.com/pentacore/media-manager/commit/1238b55fe1ad26737e0d72087693095384cca6d3))
* **actions:** strip scheduler-owned keys from agent-authored emby_library_scan payloads ([631f34c](https://github.com/pentacore/media-manager/commit/631f34cccb3c23b3aa982e035ccd1caed27bb4e4))
* **auth:** create first-time Emby users through the bootstrap-role lock ([ebde88a](https://github.com/pentacore/media-manager/commit/ebde88a64239c95b12a195c970af402f1f8aa095))
* **dashboard:** queue the stats rebroadcast with a guaranteed trailing update ([0ef8a3d](https://github.com/pentacore/media-manager/commit/0ef8a3d44d256b6334fa258c567090445074db98))
* **deploy:** trust no proxy by default and keep the chat timeout below the Octane ceiling ([44d7fc8](https://github.com/pentacore/media-manager/commit/44d7fc8484da90c66148220727235d10577f7dd3))
* **emby:** coalesce webhook-triggered library scans into one trailing refresh ([d8a385b](https://github.com/pentacore/media-manager/commit/d8a385b54467f239acb2616a6d813d1c4191c35a))
* **emby:** keep the custom ModelNotFoundException message on a missing scan connection ([be69960](https://github.com/pentacore/media-manager/commit/be69960085decac7bf56cfb1e2bfc00bceef01c8))
* **media-replacement:** search candidates before taking the submit lock ([f142297](https://github.com/pentacore/media-manager/commit/f1422974fa8e9c9984df6213dc81968be63af907))
* **monitoring:** give health-check triggers their own throttle bucket ([997f58a](https://github.com/pentacore/media-manager/commit/997f58a8673af195fb81527adab7ef943563cafc))
* **monitoring:** restrict and throttle on-demand health checks to members ([68b574c](https://github.com/pentacore/media-manager/commit/68b574c4f93d29ece5ec38df1b46673640642fa9))
* **monitoring:** toast a throttled health-check trigger instead of the raw error dialog ([8a41fbb](https://github.com/pentacore/media-manager/commit/8a41fbb5dea5979b1abce10451f9c8bca43044e6))
* **seerr:** run bulk request clears in a queued job ([ceec46d](https://github.com/pentacore/media-manager/commit/ceec46d6731b3cbd9e14a733d2ab360011cba46d))
* **support:** flag the Symfony PRIVATE_SUBNETS and REMOTE_ADDR trusted-proxy keywords as broad ([2e99d2f](https://github.com/pentacore/media-manager/commit/2e99d2f3b86f13b4074bf64a81021f262d9b5291))
* **users:** never let a delete or role change remove the last admin ([995c16f](https://github.com/pentacore/media-manager/commit/995c16f0609e8c3043689a5d59db08270e7b04f2))
* **webhooks:** mark events processed after the handler returns so failed runs stay retryable ([3adc63e](https://github.com/pentacore/media-manager/commit/3adc63e41a57d09e574ae302213b1b60c3667edd))
* **webhooks:** mark no-handler events processed and fix misleading test names ([14575b4](https://github.com/pentacore/media-manager/commit/14575b42be7190f741683370dc7c345785f1e51a))
* **webhooks:** rate-limit and size-cap webhook ingress, dedupe with capture off, drop the query token ([ffebd4b](https://github.com/pentacore/media-manager/commit/ffebd4b8734e1cc09fb40e6224848631b485ea0f))


### Features

* **ai:** warn when a hard budget cannot price the selected models ([8041dc1](https://github.com/pentacore/media-manager/commit/8041dc1dbc79ec598b1ce09a37903044a5bf8ee4))
* **http:** send baseline security headers and opt-in HSTS ([29859e1](https://github.com/pentacore/media-manager/commit/29859e195e25812df0f9f166fbd5eab3b8955d65))

# [1.22.0](https://github.com/pentacore/media-manager/compare/v1.21.0...v1.22.0) (2026-09-27)


### Bug Fixes

* **actions:** describe delete rules as covering manual media-page deletes ([ddfbc13](https://github.com/pentacore/media-manager/commit/ddfbc139869f5c6393835f224852e233d923e334))
* **actions:** exempt manual requests from the chat AI advisory override ([b278e67](https://github.com/pentacore/media-manager/commit/b278e6755d0f201f319ca7244df3e5d0e6ae4432))
* **admin:** clarify the LiteLLM cross-check hint ([800e91b](https://github.com/pentacore/media-manager/commit/800e91b0f1247f0b5449e51b01fed95dad562322))
* **auth:** open self-registration only for the bootstrap admin unless enabled ([961f566](https://github.com/pentacore/media-manager/commit/961f56693ceda48cef7b045716c1a8610e7e11d9))
* **auth:** resolve the welcome page's canRegister per request ([be4206e](https://github.com/pentacore/media-manager/commit/be4206e437cf12f5a26a084235ef20cce2d5d8b9))
* **auth:** send 2FA users from Emby login to the two-factor challenge ([07509f5](https://github.com/pentacore/media-manager/commit/07509f56b9cbf55d7e3900d5a1ae4fc233e42dec))
* **chat:** use the full toolset when no tool group clears the inclusion threshold ([30fa8ac](https://github.com/pentacore/media-manager/commit/30fa8accdb467705ce2a19d8af4c11f536101ca9))
* **decision-agent:** bind stuck-download removals to downloadInfo.downloadId too ([cbdb2a6](https://github.com/pentacore/media-manager/commit/cbdb2a6792f33de8b0941f664726d19bebe705e4))
* **decision-agent:** force approval for destructive proposals and bind them to the event subject ([d8704c2](https://github.com/pentacore/media-manager/commit/d8704c22ace22a475f7eff9593a0bfa5fe182974))
* **decision-agent:** inspect stuck imports on the event's instance and bind imports to its download ([62a0ea0](https://github.com/pentacore/media-manager/commit/62a0ea070cdb4407e2728e070c208b92541963a5))
* **decision-agent:** keep subject binding and connection pinning when capture trims the event ([879526f](https://github.com/pentacore/media-manager/commit/879526f065e58481ffa1b65cb63ab583c18164d7))
* **decision-agent:** key stuck-import cooldowns on the download, not the series ([22fabf1](https://github.com/pentacore/media-manager/commit/22fabf15a5dfb6474d86be37536bb811d7e8e8a5))
* **emby:** rate-limit Emby account linking ([70fcb88](https://github.com/pentacore/media-manager/commit/70fcb88251486fd14f06aaefa580feb512dc43d9))
* **jobs:** expire unique-job locks so lost workers cannot block retries ([9c8050c](https://github.com/pentacore/media-manager/commit/9c8050c6c8b4e19961571c57cf60ab0f9d16cb93))
* **jobs:** hold the subtitle advisor unique lock for its full retry window ([65326f6](https://github.com/pentacore/media-manager/commit/65326f699cd7030906b47c9fd1c0ccc159757cfe))
* **media:** disable the delete confirm button while the delete is in flight ([8c1e364](https://github.com/pentacore/media-manager/commit/8c1e36471a5aa7c631741637be163d70682f9a41))
* **media:** queue manual series and movie deletes through the action pipeline ([9812e52](https://github.com/pentacore/media-manager/commit/9812e52fc60c61118925e5a38b42844c6add35d4))
* **notifications:** restrict the generic webhook channel to admins ([73e563f](https://github.com/pentacore/media-manager/commit/73e563f76442cfff4a659ec7d27876be93f8c105))
* **notifications:** use an after() hook instead of an inline closure rule ([49eae6c](https://github.com/pentacore/media-manager/commit/49eae6ccdc28db3635dddb76c7c2f5463c67b495))
* **pricing:** bill OpenRouter reasoning at the completion rate when unset ([c8ceb39](https://github.com/pentacore/media-manager/commit/c8ceb399913517cb4c72dc872a7ba05b916149ce))
* **pricing:** emit xai alias candidates and widen tier detection ([41265a2](https://github.com/pentacore/media-manager/commit/41265a24e31bc6b56a8cb7a5c0df01275b316f3e))
* **pricing:** merge xai per model and quiet an unconfigured xai toggle ([6189581](https://github.com/pentacore/media-manager/commit/61895815bcda476b94487c08d14f24b397d027f7))


### Features

* **ai-usage:** show each invocation's input and output in the drill-down ([581b025](https://github.com/pentacore/media-manager/commit/581b025e3a6ab3fbcfedc35e567a4b7c453686b0))
* **pricing:** litellm price map source ([c8bd38e](https://github.com/pentacore/media-manager/commit/c8bd38e1ff43d161c5b303f4ae2053e12fd073d7))
* **pricing:** openrouter models api pricing source ([d11b300](https://github.com/pentacore/media-manager/commit/d11b300fe3fb24e2da1e344e92cb3d46446fb5b1))
* **pricing:** pricing catalog with per-provider source precedence ([b784a76](https://github.com/pentacore/media-manager/commit/b784a76c9d55d4e954ecde32b98c501e6e4fb486))
* **pricing:** reconcile models.dev and litellm prices ([7b1b043](https://github.com/pentacore/media-manager/commit/7b1b04363f04000316d4f1fcea2348efa3b98899))
* **pricing:** refresh prices through the structured pricing catalog ([d403720](https://github.com/pentacore/media-manager/commit/d40372038b53ed87e1f7211cc5f829df52aa5603))
* **pricing:** settings and admin switches for structured pricing sources ([8127163](https://github.com/pentacore/media-manager/commit/81271638f3f147af6d24dbb6bcf576ea38fb5dfc))
* **pricing:** tag price candidates with their source and add structured source kinds ([3348122](https://github.com/pentacore/media-manager/commit/3348122aa0683fcae815c64956e3d44cd09b3475))
* **pricing:** xai first-party pricing api source ([ade5c7d](https://github.com/pentacore/media-manager/commit/ade5c7d4a5e867af984fb30b92f6a4eb1f900027))

# [1.21.0](https://github.com/pentacore/media-manager/compare/v1.20.0...v1.21.0) (2026-09-27)


### Features

* **pricing:** dedicated price updater model and in-use model creation ([b871b41](https://github.com/pentacore/media-manager/commit/b871b417141b8f79ebf7becb365228f82d099c43))

# [1.20.0](https://github.com/pentacore/media-manager/compare/v1.19.0...v1.20.0) (2026-09-26)


### Bug Fixes

* **actions:** keep replacement files and evidence beside described details ([87d2fc8](https://github.com/pentacore/media-manager/commit/87d2fc899126d240714dcc4828d19d2d90f06f98))
* **actions:** read describer payload flags the way the executors do ([a862799](https://github.com/pentacore/media-manager/commit/a86279964df2462ef519c73b943fbfccfa43dbdc))
* **actions:** tidy fallback nouns, agent schema example and demo actions ([76087a8](https://github.com/pentacore/media-manager/commit/76087a88577a67193bbfb7717ecdcea70ff30112))
* **chat:** write AG-UI frames when Octane hands back the generator ([ceace1b](https://github.com/pentacore/media-manager/commit/ceace1bbd97e7a89815cbea8a7762e57fcd74c11))
* **media-replacement:** drop LLM-written reason from the approval-card details ([ef6b490](https://github.com/pentacore/media-manager/commit/ef6b49035265861f18d4255770463e2edc989721))


### Features

* **actions:** add action request description columns and value objects ([8947c50](https://github.com/pentacore/media-manager/commit/8947c509da4695482447fbcf9ae1e54208baa3a8))
* **actions:** describe arr, seerr, emby and download actions ([5a53170](https://github.com/pentacore/media-manager/commit/5a5317075a121becb0ad448ef33553c73b7d1290))
* **actions:** describe webhook-triggered deletes and library scans ([2d6209b](https://github.com/pentacore/media-manager/commit/2d6209b26c33d07a7abbff7ab6215f5f75bdb945))
* **actions:** expose action descriptions to the queue and dashboard ([ae564ef](https://github.com/pentacore/media-manager/commit/ae564ef80ccda6968e9244b4dae3fa845b264c31))
* **actions:** persist action descriptions and gate unverified ones ([687e37c](https://github.com/pentacore/media-manager/commit/687e37ce371ff24ead6e1e12a1dca0969bbaf4f4))
* **actions:** require a description on every action request ([96521ea](https://github.com/pentacore/media-manager/commit/96521ea954a3440211662ba4f993926f28cfa7a8))
* **actions:** resolve action target names server-side ([23c6566](https://github.com/pentacore/media-manager/commit/23c656673efb561f72f8725f48cd1d7607f128a9))
* **actions:** show action descriptions, details and ai reasoning ([3925763](https://github.com/pentacore/media-manager/commit/39257636979384cdd35a9bc9e2500616cb15cd41))
* **ai:** describe chat-queued actions and tag them as chat origin ([3e96665](https://github.com/pentacore/media-manager/commit/3e966650ba52beefad84c86aff426c8f1f7f46dc))
* **ai:** describe decision agent proposals and download actions ([5c6ebe3](https://github.com/pentacore/media-manager/commit/5c6ebe3961e3a61284023bdc00899a88424f0a9a))
* **bazarr:** describe subtitle operations on the action queue ([6df285e](https://github.com/pentacore/media-manager/commit/6df285e1d55e70c2143d5e91bf18999fb5af370b))
* **media-replacement:** describe replacement requests on the action queue ([7e87453](https://github.com/pentacore/media-manager/commit/7e874531a5a277250c829a9e7b75491626db2e79))

# [1.19.0](https://github.com/pentacore/media-manager/compare/v1.18.1...v1.19.0) (2026-09-25)


### Bug Fixes

* **ai-usage:** bill steps completed before a provider failover ([0410e81](https://github.com/pentacore/media-manager/commit/0410e8170e8ebe20fbed8382b9c3dc648f976bc8))
* **ai-usage:** keep exclusive token columns under 1.0 inclusive usage ([170947d](https://github.com/pentacore/media-manager/commit/170947d39eed2b1ecc901f631edd420d73e9162a))
* **ai:** run structured sub-agents as prompts when a parent streams ([83c0f1e](https://github.com/pentacore/media-manager/commit/83c0f1e49a83e6a60c669a5409c384cdba8f2e89))
* **chat:** answer a mid-run hard-cap stop on send() with the budget message ([a178401](https://github.com/pentacore/media-manager/commit/a1784016616b5b7c906f8c508bf24c06eb216fab))
* **chat:** keep a failed turn's stored conversation active ([16b71f6](https://github.com/pentacore/media-manager/commit/16b71f6c6ba91dd202c9188e021dd6fbdd2e0505))
* **chat:** keep AG-UI frames inside a capturing output buffer ([4933cb3](https://github.com/pentacore/media-manager/commit/4933cb32ad54a60f9441f19ab2688a238d1752ff))
* **chat:** serve non-image attachments as hardened downloads ([1885f7a](https://github.com/pentacore/media-manager/commit/1885f7aa1664789a331d17499cd739f3bcb89808))
* **chat:** show a running sub-agent's output under its tool chip ([c26aff5](https://github.com/pentacore/media-manager/commit/c26aff5b9b07ee59552af226900ee8e98a9bbaf7))
* **chat:** show Files API attachments in conversation history ([0c6a7ea](https://github.com/pentacore/media-manager/commit/0c6a7ea193088e3a81927946c24c0a1691f5fd61))
* **chat:** show the explanation, not the error code, when a blocking turn fails ([ffe0dd6](https://github.com/pentacore/media-manager/commit/ffe0dd6f1bc814ce98d51f685bb4a2ee1ea82de8))
* **decision-agent:** claim the subject cooldown only for an agent run ([c2fecbe](https://github.com/pentacore/media-manager/commit/c2fecbea2090b6446a774df0e7c5b085bd1fa334))
* **decision-agent:** record the gate question in skipped_by_gate summaries ([86281c4](https://github.com/pentacore/media-manager/commit/86281c4126504f24d0d962bf26be7760aa3d0d48))


### Features

* **admin:** paginate AI conversation transcripts ([a1aaf79](https://github.com/pentacore/media-manager/commit/a1aaf79878501e09eeb198427c93c0cb846ea1f4))
* **admin:** render 1.0 conversation steps and turn status in transcripts ([4cf0a5e](https://github.com/pentacore/media-manager/commit/4cf0a5e88d8b068b7e6dd89f3b2f814610c61097))
* **ai-settings:** classification, reranking and sub-agent settings with capability checks ([976d3e4](https://github.com/pentacore/media-manager/commit/976d3e4f6f3723054f65ee91050800d938049c8e))
* **ai-tools:** validate tool arguments and report invalid_arguments ([f809f40](https://github.com/pentacore/media-manager/commit/f809f407187d3a59d69f8cdd14970e48b44850eb))
* **ai-usage:** add kind, failure and sub-agent telemetry columns ([39332a3](https://github.com/pentacore/media-manager/commit/39332a3162b91e9de6b23901ffa4b73f12463d80))
* **ai-usage:** bill completed steps of failed agent runs ([49cbbbe](https://github.com/pentacore/media-manager/commit/49cbbbeae640d856b0335a4cf5df5a00fdd1b6e4))
* **ai-usage:** bill embeddings and reranking, add search-unit pricing ([b6cd2cf](https://github.com/pentacore/media-manager/commit/b6cd2cfa77e4987cc8f9625e6d9dfcd3a9d72e14))
* **ai-usage:** kind filter, failed runs, tool stats and sub-agent drill-down ([92dc07b](https://github.com/pentacore/media-manager/commit/92dc07b3abd54e813d0286d557d7cfa87be0a167))
* **ai-usage:** link sub-agent usage rows to their parent run ([377859f](https://github.com/pentacore/media-manager/commit/377859f5f867e904f486788160995af624b19e20))
* **ai-usage:** record tool duration and failures ([694637c](https://github.com/pentacore/media-manager/commit/694637ccc349813ce5b4e3ea7fc587f83de3d6fd))
* **ai:** answer-on-final-step and per-step budget middleware ([2957295](https://github.com/pentacore/media-manager/commit/2957295da178c709bbd6c864d47ae8129013f3a9))
* **ai:** fail-open classifier with usage billing ([2521b76](https://github.com/pentacore/media-manager/commit/2521b76559bf4d23182b902cb51889ec4171a8df))
* **ai:** migrate conversation messages to 1.0 steps storage ([fe15564](https://github.com/pentacore/media-manager/commit/fe15564d2a8835ae44e55a3543b6b3f1645d520c))
* **ai:** read-only investigation sub-agents for stuck downloads and media files ([06863e8](https://github.com/pentacore/media-manager/commit/06863e81b94d90000213dc7d786196ffa68d1615))
* **ai:** repair unknown tool calls and cache stable prompts ([e39a6e7](https://github.com/pentacore/media-manager/commit/e39a6e7333f5a9da79e8510cb7f9ff66745b1000))
* **chat:** accept image, text and PDF attachments on chat turns ([0028faa](https://github.com/pentacore/media-manager/commit/0028faab943afafe597d1b5a65c8098c00044431))
* **chat:** classification-routed MediaAgent toolsets ([c16276b](https://github.com/pentacore/media-manager/commit/c16276b7ff3a7c944adbce152bb3ad130aa0ee06))
* **chat:** defer non-core tools behind hosted ToolSearch when the chain supports it ([4f42577](https://github.com/pentacore/media-manager/commit/4f425774fbb20ee45cc33d0d3f7d63cfcf41e374))
* **chat:** paginate history and show reasoning, attachments and failed turns ([a4ff6f5](https://github.com/pentacore/media-manager/commit/a4ff6f58781c51015cc2c98586b57d142af76ece))
* **chat:** parse AG-UI streams with tool chips, reasoning and attachments ([55f6d53](https://github.com/pentacore/media-manager/commit/55f6d53cd6a22194b18093a6dd8fba04addba605))
* **chat:** stream turns over AG-UI with friendly errors and fix channel ownership ([708183d](https://github.com/pentacore/media-manager/commit/708183dfdbbebd38ebf0e0ab13ba3d5a643edba1))
* **decision-agent:** classification gate before webhook agent runs ([1c1efe5](https://github.com/pentacore/media-manager/commit/1c1efe5579715e2a3f0c7014142a6b913c5c075f))
* **pricing:** code execution for the price verifier and citation audit trail ([4096490](https://github.com/pentacore/media-manager/commit/409649081ff7e48a697edf1d2fa826d356518000))
* **search:** admin-selectable reranking provider ([3fe6ee0](https://github.com/pentacore/media-manager/commit/3fe6ee095f9e95cfbe9016253e30eb299b3429f8))
* **subtitles:** classification triage before Media Advisor runs ([afbb193](https://github.com/pentacore/media-manager/commit/afbb193dad21d4ab9b8fffc2836a066241f608e0))

## [1.18.1](https://github.com/pentacore/media-manager/compare/v1.18.0...v1.18.1) (2026-09-24)


### Bug Fixes

* **ai:** adapt to laravel/ai 0.11 stream errors and event signatures ([5b4534a](https://github.com/pentacore/media-manager/commit/5b4534a402607b6c461c13de0e55201e130566ca))

# [1.18.0](https://github.com/pentacore/media-manager/compare/v1.17.0...v1.18.0) (2026-09-24)


### Bug Fixes

* **notifications:** guard destination channel changes, share destination validation rules, pin once-per-destination fan-out ([822ce4e](https://github.com/pentacore/media-manager/commit/822ce4ea6bab343601cd38e99b56ae74c25da061))
* **notifications:** guard PushChannel against an empty DRIVER and cover the base send() branches ([b685dc2](https://github.com/pentacore/media-manager/commit/b685dc267a1fbaa40f235ad0f6323b6ee6d499e8))
* **notifications:** hide destination config from serialization and refresh docblocks ([90fc21d](https://github.com/pentacore/media-manager/commit/90fc21d8fe7f685a9598b456a238606e142c5796))
* **notifications:** keep the preferences page to the channels it can persist until the push destinations land ([d4e35aa](https://github.com/pentacore/media-manager/commit/d4e35aac46f929aa15fcb8c080a64fd755dbaa10))
* **notifications:** scrub push test failures, keep typed secrets on validation errors, tighten test-send guards ([43a4144](https://github.com/pentacore/media-manager/commit/43a414424e698a25d78fb6753e76bec3669ae5d6))


### Features

* **ai:** enforce configured model rate limits behind an admin toggle ([3fc3377](https://github.com/pentacore/media-manager/commit/3fc3377761b3c5ff3143b42cfeaf0b398be9e189))
* **notifications:** add Discord webhook push channel ([b59976c](https://github.com/pentacore/media-manager/commit/b59976cc02fa1d9c4dda6cd732b4426e778f7bf2))
* **notifications:** add global NotificationDestination model routed through the preference resolver ([5ba1dd3](https://github.com/pentacore/media-manager/commit/5ba1dd3295c729107e851d3ff3ff9d9789b19f9d))
* **notifications:** add push channel type and severity enums, telegram token config ([1ffb6a5](https://github.com/pentacore/media-manager/commit/1ffb6a5559fb999b839d7758afc9b698bc04b7a4))
* **notifications:** add signed generic webhook push channel ([0eda417](https://github.com/pentacore/media-manager/commit/0eda4175ce7a65fa03b21b3e021e605500376399))
* **notifications:** add Telegram bot push channel ([69fe1e7](https://github.com/pentacore/media-manager/commit/69fe1e7a4769d16af877c889057db3157be7586b))
* **notifications:** admin page to manage global notification destinations ([ceb0556](https://github.com/pentacore/media-manager/commit/ceb05567b7195999986a709e5ae8274edcfe5657))
* **notifications:** let users set discord, telegram and webhook destinations and test each channel ([6e440a1](https://github.com/pentacore/media-manager/commit/6e440a1d366593e2b5c862cb059908ce09c6471e))
* **notifications:** per-user discord, telegram and webhook destinations with preference flags ([666b3f9](https://github.com/pentacore/media-manager/commit/666b3f907b9cc4023950918ddaae2a74f092b6a6))
* **pricing:** configure per provider whether the price refresh adds new models ([4fab365](https://github.com/pentacore/media-manager/commit/4fab365b6c0865bcc877664b583b8fa47efa1c60))

# [1.17.0](https://github.com/pentacore/media-manager/compare/v1.16.2...v1.17.0) (2026-09-04)


### Bug Fixes

* **actions:** corrected race test to verify catch block fires ([0b57408](https://github.com/pentacore/media-manager/commit/0b57408ab0688b29cdc498556aa12f1154039a54))
* **actions:** delegate race handling to Laravel 13 firstOrCreate ([d9897a5](https://github.com/pentacore/media-manager/commit/d9897a544c720077ab14f549b108fee90d6f988e))
* **actions:** self-heal action types without resetting admin gates ([70f3bbd](https://github.com/pentacore/media-manager/commit/70f3bbde3622788ff5959e9cbfb0217e5a197c0d))
* **deploy:** seed action types after migrations on both entrypoint paths ([08a80c0](https://github.com/pentacore/media-manager/commit/08a80c05b55d0727eacadfce0857beda7fe026bf))
* **media-replacement:** bound operator-cancel queue paging ([b3e63e8](https://github.com/pentacore/media-manager/commit/b3e63e864a00e1ccbda67a49528602aac1063ccd))
* **media-replacement:** cancel the attempts search debounce and unfilter the All tile ([ac29d08](https://github.com/pentacore/media-manager/commit/ac29d082a8a7bde442346de652ec53d599fcc066))
* **media-replacement:** close the manual replace review-fix gaps ([bfa5e9e](https://github.com/pentacore/media-manager/commit/bfa5e9e9303afa4f0e081ac6cbf494924d357fcd)), closes [guard-throu#dispatch](https://github.com/guard-throu/issues/dispatch)
* **media-replacement:** drop leaked snapshot fields, distinguish toasts ([f39f929](https://github.com/pentacore/media-manager/commit/f39f929497d4d3c0d04b0924297b8acf636620a0))
* **media-replacement:** gate the cancel confirm dialog on can.cancel ([785a0c5](https://github.com/pentacore/media-manager/commit/785a0c5ba03882bee9203a6b01075a1f4143471a))
* **media-replacement:** reset acknowledgement when an attempt re-enters the executor ([5c9d94c](https://github.com/pentacore/media-manager/commit/5c9d94c241bdd202cf73c20d2a0dc4b673b4015a))
* **media-replacement:** serialize replacements per installed file ([c43352d](https://github.com/pentacore/media-manager/commit/c43352d2636b2d2d8e603e9f4a9cc49880fcebed))
* **media-replacement:** skip subtitle verification only on literal false ([d67fed7](https://github.com/pentacore/media-manager/commit/d67fed773dd3dad81317c1a77239d1675f453b32))
* **tests:** use withoutVite() for feature tests instead of a Blade guard ([22c3c16](https://github.com/pentacore/media-manager/commit/22c3c1636d71937e91135262b78783fa3bc82343))


### Features

* **actions:** expose the replacement attempt summary and a request preselect ([ba92c4d](https://github.com/pentacore/media-manager/commit/ba92c4d391caeec4db30b36c357c70a65dc9f3c3))
* **actions:** show the replacement attempt on the queue and refresh it live ([4e58420](https://github.com/pentacore/media-manager/commit/4e5842045cf1895d4053466f3c2fb04858a006f4))
* **media-replacement:** add a server-computed target fingerprint ([e1d5772](https://github.com/pentacore/media-manager/commit/e1d5772f644d482695c56289749fd168c6ba8897))
* **media-replacement:** add acknowledge, restore-monitoring and cancel endpoints ([d0bb209](https://github.com/pentacore/media-manager/commit/d0bb20914f74e908781a36afdfab757c990f42fc))
* **media-replacement:** add attempt acknowledgement columns and helpers ([0d8b172](https://github.com/pentacore/media-manager/commit/0d8b1720baff71c5b97c29a5e1a204951a55d1fa))
* **media-replacement:** add operator actions service for attempts ([625cb5f](https://github.com/pentacore/media-manager/commit/625cb5fea70dbdfd1e5bc108affbf808db879652))
* **media-replacement:** add the admin attempt detail endpoint ([4768e23](https://github.com/pentacore/media-manager/commit/4768e2333ff2b961d3681f7aba04bd4c5ebf58bf))
* **media-replacement:** add the admin attempts index endpoint ([c5242e3](https://github.com/pentacore/media-manager/commit/c5242e31dc8d6ba17264d37f0576906d97efaade))
* **media-replacement:** add the attempt detail page with operator actions ([2092bc5](https://github.com/pentacore/media-manager/commit/2092bc5959ac7768b56b453ef8cd04b0fcb638e2))
* **media-replacement:** add the attempts tab and list page ([3f0489e](https://github.com/pentacore/media-manager/commit/3f0489e4909243a1677020d8b80eff273d7d2666))
* **media-replacement:** add the manual candidates endpoint ([fac2b07](https://github.com/pentacore/media-manager/commit/fac2b075bb6cb7b677e2880cfa63edfe61aac730))
* **media-replacement:** add the manual inspect endpoint ([d1cc78b](https://github.com/pentacore/media-manager/commit/d1cc78b8e8fac51025b07b4e203c0de14345e893))
* **media-replacement:** add the manual replace endpoint ([4425bde](https://github.com/pentacore/media-manager/commit/4425bde0c56480831ca59d9dbbcd33a048bfbd9d))
* **media-replacement:** add the Replace file dialog to series and movie pages ([89b9bd6](https://github.com/pentacore/media-manager/commit/89b9bd631c3c085550a2069a28cc9156a4eba39a))
* **media-replacement:** broadcast attempt changes on an admin channel ([45ae1c0](https://github.com/pentacore/media-manager/commit/45ae1c01d6504e6bae85d450c2ea003d2af6229d))
* **media-replacement:** link attempt notifications to the attempt page ([53ed85e](https://github.com/pentacore/media-manager/commit/53ed85e0c88d48de0df3be2fc177b2a135b5d1eb))
* **media-replacement:** make subtitle verification optional per request ([cc0ac17](https://github.com/pentacore/media-manager/commit/cc0ac17c277cca9046801be9b319490fda04fa3f))
* **media-replacement:** move settings to their own admin page ([5961e69](https://github.com/pentacore/media-manager/commit/5961e6927e01f86d46df4955b28a29148c2cd838))
* **media-replacement:** show open attention count on the sidebar badge ([78965c6](https://github.com/pentacore/media-manager/commit/78965c677db0f356c60084798162c815fe8a4fd7))
* **monitoring:** check SABnzbd upstream releases ([9cc209c](https://github.com/pentacore/media-manager/commit/9cc209c6d4daf93add90cecf08b87e64041c874c))

## [1.16.2](https://github.com/pentacore/media-manager/compare/v1.16.1...v1.16.2) (2026-08-13)


### Bug Fixes

* **library:** cache failed intervention recomputes to stop per-render stalls ([15aed87](https://github.com/pentacore/media-manager/commit/15aed873ed0a59554577d8ad8db7e6008a1f4eb0))

## [1.16.1](https://github.com/pentacore/media-manager/compare/v1.16.0...v1.16.1) (2026-08-12)

# [1.16.0](https://github.com/pentacore/media-manager/compare/v1.15.1...v1.16.0) (2026-08-06)


### Bug Fixes

* **media-replacement:** arm the competing-grab sweep only once the download id is known ([ca26156](https://github.com/pentacore/media-manager/commit/ca26156d6bbb05a5fb2d5de36af895d29cc128ab))
* **media-replacement:** carry an unlifted suspension across a fresh-path retry ([8fe178d](https://github.com/pentacore/media-manager/commit/8fe178d840713d28ef5e086fd1edafb2d581778d))
* **media-replacement:** carry the audit's state so capture-off cannot mute it ([d44ec90](https://github.com/pentacore/media-manager/commit/d44ec900deacbabf14cbbdcca78d20767e7520c4))
* **media-replacement:** compare the same download id the sweep was armed with ([0f94931](https://github.com/pentacore/media-manager/commit/0f9493174e6260093635cbe523e4bababf682b90))
* **media-replacement:** coordinate reconciliation ([495eb43](https://github.com/pentacore/media-manager/commit/495eb433a1bea1dbd5dc6701b278a94d48373358))
* **media-replacement:** finish competing grab sweeps ([0a3c25f](https://github.com/pentacore/media-manager/commit/0a3c25fa9e6913f40399dd962747e7a72dae0b1c))
* **media-replacement:** harden the job, the cutoff and two re-reads ([b5353c1](https://github.com/pentacore/media-manager/commit/b5353c14a7e0102dc833c17ea3259201ad9d1c79))
* **media-replacement:** inspect a fresh arr state in the subtitle auditor ([f568ea2](https://github.com/pentacore/media-manager/commit/f568ea2b79839842266730c833509298a753afda))
* **media-replacement:** keep notification failures out of the sweep outcome ([e5542a1](https://github.com/pentacore/media-manager/commit/e5542a1956cfbca49a19229693e1a57238944a78))
* **media-replacement:** keep the resume's restore obligation and age it correctly ([39ba2a8](https://github.com/pentacore/media-manager/commit/39ba2a824611e492ad1d3e2f65c455d8d8cc68ab))
* **media-replacement:** let an admin clear every subtitle-check tag ([b75fca4](https://github.com/pentacore/media-manager/commit/b75fca453a0f9a3cf6cc78b2bc9cce18919d77aa))
* **media-replacement:** make the sweep's service shape one decision, not two ([90d0e60](https://github.com/pentacore/media-manager/commit/90d0e60380f8483ea3cc20ca6b59bb931ee1462c))
* **media-replacement:** preserve download identity ([0a46979](https://github.com/pentacore/media-manager/commit/0a469794117d75af1a3b42ec5a2b98faa90e9da4))
* **media-replacement:** quieten and consolidate the competing-grab sweep ([31f7b28](https://github.com/pentacore/media-manager/commit/31f7b2874d9c6b65c0f05bfdf526a3618f3f2b83))
* **media-replacement:** re-assert the suspension when resuming a cleanup ([0126616](https://github.com/pentacore/media-manager/commit/012661621af655c6cc3b66061e4600837e5ba494))
* **media-replacement:** repair monitoring left suspended on a settled attempt ([1bfd13c](https://github.com/pentacore/media-manager/commit/1bfd13c9aa3b3f644912b6f57ada5cf9c9215009))
* **media-replacement:** report indeterminate cleanup accurately ([88112a4](https://github.com/pentacore/media-manager/commit/88112a4f1a0aeb3bf977ef544eeb41bbabea7ceb))
* **media-replacement:** serialize automatic checks ([1be3402](https://github.com/pentacore/media-manager/commit/1be340251de6b6a54f218537540f54a27d2f6052))
* **media-replacement:** stop a sweep from removing a sibling attempt's download ([dd723c5](https://github.com/pentacore/media-manager/commit/dd723c58c876892462ef5ca3571b3da8ce9c5467))
* **media-replacement:** stop the blocklist from starting a second download ([513f8ff](https://github.com/pentacore/media-manager/commit/513f8ff7ff76aa27cf9969a74a71828a2c3938f9))
* **media-replacement:** trim arr tag labels where they enter the app ([cd6cdce](https://github.com/pentacore/media-manager/commit/cd6cdce42ab947d858cddc88969ef69033c14f73))
* **media-replacement:** widen the sibling keep set to protect batch packs ([8770ade](https://github.com/pentacore/media-manager/commit/8770ade1fedb394aa0512a80236b9eed3de5fa92))


### Features

* **arr:** read instance tags ([a50767c](https://github.com/pentacore/media-manager/commit/a50767cb0770d2926e7cfda935d2fcb2c80379c3))
* **media-replacement:** accept the subtitle-check switches on the AI settings form ([8c33828](https://github.com/pentacore/media-manager/commit/8c33828d836d5befc97b744f470f7827fdc2673c))
* **media-replacement:** add competing-grab sweeper ([965f106](https://github.com/pentacore/media-manager/commit/965f106ffdf3d0d5d9d8fcaf7ce8f68d9b2e0ebd))
* **media-replacement:** add delayed competing-grab sweep passes ([c1a79f9](https://github.com/pentacore/media-manager/commit/c1a79f9447a6c61024b70fe7ed29029b19a98ac5))
* **media-replacement:** add the global subtitle-check switches ([3715f3c](https://github.com/pentacore/media-manager/commit/3715f3c3dff2759f3b5dddf3741a15addb0c3572))
* **media-replacement:** add the subtitle-check controls to the AI settings panel ([ae7b675](https://github.com/pentacore/media-manager/commit/ae7b675ea92cf9248a97da7ed3aa72ca2d08b52f))
* **media-replacement:** add the subtitle-check tag picker to the connection form ([1b26a24](https://github.com/pentacore/media-manager/commit/1b26a247388570ea805c3492257518ba43ef9b93))
* **media-replacement:** audit tagged imports for required subtitles ([adc1c12](https://github.com/pentacore/media-manager/commit/adc1c1260d5576fa0db14ebdd20a7ce9af21616f))
* **media-replacement:** expose download-to-attempt correlation ([f915f64](https://github.com/pentacore/media-manager/commit/f915f64ed4b5a532a062abfc9f6e19400011425d))
* **media-replacement:** read and persist subtitle-check tags on a connection ([ce30d8c](https://github.com/pentacore/media-manager/commit/ce30d8cca052e1477d6af2c9d6272c98f3bc4b57))
* **media-replacement:** run the subtitle check on import webhooks ([79b1b3e](https://github.com/pentacore/media-manager/commit/79b1b3e69750f0e7f497ecaef59cc4a8bf426497))
* **media-replacement:** store subtitle-check tag labels per connection ([41906a8](https://github.com/pentacore/media-manager/commit/41906a88220927176f46252b44703a30a9353f5d))
* **media-replacement:** sweep competing grabs detected via the Grab webhook ([3613376](https://github.com/pentacore/media-manager/commit/361337665e20c2bbb1cb95e28e066ed06b08f9a7))


### Performance Improvements

* **media-replacement:** defer the arr tags prop ([00f4fc7](https://github.com/pentacore/media-manager/commit/00f4fc781daeb58c8fe60471fbbc930cda8764a7))

## [1.15.1](https://github.com/pentacore/media-manager/compare/v1.15.0...v1.15.1) (2026-07-31)


### Bug Fixes

* **ssr:** inline npm deps into the SSR bundle ([4c14f7f](https://github.com/pentacore/media-manager/commit/4c14f7f5501b504866e98d1cf0d08cd27d11abf0))

# [1.15.0](https://github.com/pentacore/media-manager/compare/v1.14.1...v1.15.0) (2026-07-31)


### Features

* **ai:** make the assistant panel wider and drag-resizable ([4eea4d5](https://github.com/pentacore/media-manager/commit/4eea4d536a8b7a3014e80c0eb58ab4f2d5ef644b))

## [1.14.1](https://github.com/pentacore/media-manager/compare/v1.14.0...v1.14.1) (2026-07-31)


### Bug Fixes

* **ai:** raise the chat timeout and make it configurable ([d0d567b](https://github.com/pentacore/media-manager/commit/d0d567b19bfb20c5b74123c280952479c1097427))

# [1.14.0](https://github.com/pentacore/media-manager/compare/v1.13.2...v1.14.0) (2026-07-31)


### Bug Fixes

* **bazarr:** apply spec-audit remediation waves A-C ([9fa955c](https://github.com/pentacore/media-manager/commit/9fa955cd79a16e958eb4ac7ddc931c84e69919f1)), closes [#132](https://github.com/pentacore/media-manager/issues/132)
* **bazarr:** bypass cache for health checks ([95ef98b](https://github.com/pentacore/media-manager/commit/95ef98bd60868cd0336e8b0b0112ad26687b6c16))
* **bazarr:** close the fifth-round review findings ([84f2381](https://github.com/pentacore/media-manager/commit/84f23818845b92fe422f02064ca31026df83bb28))
* **bazarr:** close the fourth-round review findings ([56c7bb9](https://github.com/pentacore/media-manager/commit/56c7bb93d6305db58c3956e8694952e0f023b7dc))
* **bazarr:** close the lifecycle and upload-identity gaps found in review ([87a8ed4](https://github.com/pentacore/media-manager/commit/87a8ed4fb028f2949d9aa6686b56300d68a864e9))
* **bazarr:** close the second-round review findings ([8469e83](https://github.com/pentacore/media-manager/commit/8469e83ddf94036ca9b75d97e938e10e3e6c2aa3))
* **bazarr:** close the third-round review findings ([ea35da3](https://github.com/pentacore/media-manager/commit/ea35da32c445f9416d590858e0de0cbf547a49fc))
* **bazarr:** correct action locking, download correlation and live revalidation ([e653287](https://github.com/pentacore/media-manager/commit/e6532870599b8f243ce5453dd8452281c537cc9e)), closes [#132](https://github.com/pentacore/media-manager/issues/132)
* **bazarr:** correlate the verification claim to its own queue message and phase ([f03e594](https://github.com/pentacore/media-manager/commit/f03e594f5d71eb1678cfbe47c9730aa127ff4990))
* **bazarr:** drop the material-identity constraint portably in the partial index migration ([f128b52](https://github.com/pentacore/media-manager/commit/f128b522deb66019f1dfa9a34ea60268026b666e))
* **bazarr:** gate subtitle operations on discovered Bazarr capabilities ([f5fc636](https://github.com/pentacore/media-manager/commit/f5fc636dcef7860d996da8e8568f215a5a7e55cb))
* **bazarr:** guard mapping edit edge cases ([761dd0e](https://github.com/pentacore/media-manager/commit/761dd0e0605beed73f053ce5a3800a028095deaa))
* **bazarr:** harden advisor execution ([defbeae](https://github.com/pentacore/media-manager/commit/defbeaefcd679d11086f19e30bd1fc33c4a46b40))
* **bazarr:** harden subtitle workflow records ([e1138a6](https://github.com/pentacore/media-manager/commit/e1138a6505b5e6db67321274ee391f56a091f8fd))
* **bazarr:** honour capabilities and case-sensitive tool actions ([a49e694](https://github.com/pentacore/media-manager/commit/a49e694eec2e97e530451c399e9e19a427171d94))
* **bazarr:** let transient probe failures use the job's retries ([2982181](https://github.com/pentacore/media-manager/commit/2982181aabbde4091405397a1756714ceb70343f))
* **bazarr:** make the notification contract usable and redact durable text ([af20400](https://github.com/pentacore/media-manager/commit/af204009ce966e18bc8f226457e45fb4108ee6aa))
* **bazarr:** paginate the merged inventory stream and bound discovery scans ([143a990](https://github.com/pentacore/media-manager/commit/143a9905815428f7b537e602ee05014189d69fcc)), closes [#132](https://github.com/pentacore/media-manager/issues/132)
* **bazarr:** park a waiting case when targeted verification expires ([7c47f1d](https://github.com/pentacore/media-manager/commit/7c47f1daf583b3b2647bde88fb4094d8d7957bdd))
* **bazarr:** preserve json exception contract ([df35569](https://github.com/pentacore/media-manager/commit/df35569bae04ef374028c6cf4de048f7f2c874f3))
* **sidebar:** match nav active state by prefix so nested pages open their parent ([ee96722](https://github.com/pentacore/media-manager/commit/ee967228dcdf49f759ea4b13e9ddf03d361419a6))
* **tests:** stop browser fakes from swallowing the Inertia SSR render ([820424b](https://github.com/pentacore/media-manager/commit/820424b8183ad0633af541bf844f7466f1a7ebe7))


### Features

* **bazarr:** add advisor subtitle tools ([1d5e07a](https://github.com/pentacore/media-manager/commit/1d5e07a7fc57ff90ced44ef27af66948929ac155))
* **bazarr:** add paginator and filter controls to the Subtitle Center lists ([7191c6e](https://github.com/pentacore/media-manager/commit/7191c6e528972d270298830e54b866ac51d48ab6))
* **bazarr:** add read api client ([d77c4e3](https://github.com/pentacore/media-manager/commit/d77c4e3d0141596e74c2b0255a8b0f0b9d0a20ec))
* **bazarr:** add subtitle advisor agent ([a7aab72](https://github.com/pentacore/media-manager/commit/a7aab72883409b431e7005783e6457ea6b684ae8))
* **bazarr:** add subtitle center ([331e8ce](https://github.com/pentacore/media-manager/commit/331e8cef80f24c4fa16baf03a0f3168eb12c21e5))
* **bazarr:** add subtitle operation client ([b4ecb1f](https://github.com/pentacore/media-manager/commit/b4ecb1f044c5848d0750110a70a135d076915c40))
* **bazarr:** add subtitle workflow records ([5c03def](https://github.com/pentacore/media-manager/commit/5c03def433a86a2b7a1cc9568221d4a168ae35e7))
* **bazarr:** automate subtitle download requests ([075d796](https://github.com/pentacore/media-manager/commit/075d7965eefcf54fa1470e2a2297c8dc9393fac0))
* **bazarr:** configure connection mapping ([2d5d38a](https://github.com/pentacore/media-manager/commit/2d5d38a270a44aefe0a16bcacfb8e6701d969a89))
* **bazarr:** configure subtitle automation ([221db63](https://github.com/pentacore/media-manager/commit/221db6314be598b616a00610af007d8df3147c10))
* **bazarr:** correlate replacement outcomes ([9a40429](https://github.com/pentacore/media-manager/commit/9a40429c2105319a948ae947b3d897d710b0a8b7))
* **bazarr:** detect api capabilities ([a9855e8](https://github.com/pentacore/media-manager/commit/a9855e8297a7ec79ff6ec9f8a1059c8f7b9a3eca))
* **bazarr:** execute approved subtitle actions ([b24e0d9](https://github.com/pentacore/media-manager/commit/b24e0d9a5a3f233dfddcdf8314d81adfa759acc4))
* **bazarr:** fingerprint subtitle cases ([1a10cde](https://github.com/pentacore/media-manager/commit/1a10cdeacce34286378a0e151516a7f732d34983))
* **bazarr:** govern subtitle case lifecycle ([98736db](https://github.com/pentacore/media-manager/commit/98736db115569c15ae79556d56d459f560020277))
* **bazarr:** integrate service health ([c91a499](https://github.com/pentacore/media-manager/commit/c91a49951784656756696287047dea4e30f8c53b))
* **bazarr:** investigate escalations with advisor ([752ca7e](https://github.com/pentacore/media-manager/commit/752ca7e87238f6566228b5f03b6dbc5225673f12))
* **bazarr:** manage non-secret settings ([29c833d](https://github.com/pentacore/media-manager/commit/29c833de7b61d09a7bdb7d69271642d9f28921dd))
* **bazarr:** map arr connections ([8ed58f7](https://github.com/pentacore/media-manager/commit/8ed58f768492b844809b104655f3c48091fc323a))
* **bazarr:** project advisor escalation ([0b19fbc](https://github.com/pentacore/media-manager/commit/0b19fbc6061bb716b9b4757ce0bd88872840561f))
* **bazarr:** project subtitle inventory ([8e0925e](https://github.com/pentacore/media-manager/commit/8e0925e8f4b0ff297fe066b4e5b8bc8269ad6c6f))
* **bazarr:** queue safe advisor replacements ([5df93b5](https://github.com/pentacore/media-manager/commit/5df93b555635785b3b694b2c81d5d6a787eddf0b))
* **bazarr:** reconcile notification hints ([eff8b50](https://github.com/pentacore/media-manager/commit/eff8b5004a6daa2bb11c98380932e66823569560))
* **bazarr:** reconcile subtitle cases ([3c895c5](https://github.com/pentacore/media-manager/commit/3c895c559e8701ad884408dde55e12fedc7054ff))
* **bazarr:** register service type ([4d48ae1](https://github.com/pentacore/media-manager/commit/4d48ae19e1c27dcfda13f7ceb39240447c06d5f7))
* **bazarr:** request subtitle operations ([86ac057](https://github.com/pentacore/media-manager/commit/86ac057841e91d0dc64f379c1a278b4c1406d9f8))
* **bazarr:** run replacement advisor ([2ec06c0](https://github.com/pentacore/media-manager/commit/2ec06c028a16752b4c3f91618a3308b0a783e583))
* **bazarr:** stage subtitle uploads ([ad2d7f0](https://github.com/pentacore/media-manager/commit/ad2d7f04dd45bc65abf7240ae439439ae0683c34))
* **bazarr:** surface subtitle escalations ([4ab668e](https://github.com/pentacore/media-manager/commit/4ab668e2da9fed962499b8c5769f157f0a428dab))
* **sidebar:** collapsible sub-groups for the admin section ([b47aa98](https://github.com/pentacore/media-manager/commit/b47aa981d6ad7d12c0a56ea55efef1a7b45a0894))

## [1.13.2](https://github.com/pentacore/media-manager/compare/v1.13.1...v1.13.2) (2026-07-22)


### Bug Fixes

* **media-replacement:** supply seriesId and episodeIds on Sonarr override grabs ([0631e6f](https://github.com/pentacore/media-manager/commit/0631e6f7461c01ec5ba815c656c76feb525e3225))

## [1.13.1](https://github.com/pentacore/media-manager/compare/v1.13.0...v1.13.1) (2026-07-22)


### Bug Fixes

* **security:** update vulnerable runtime dependencies ([627f1f3](https://github.com/pentacore/media-manager/commit/627f1f3bb9f3c8397f311361fb68e99705ee742e))

# [1.13.0](https://github.com/pentacore/media-manager/compare/v1.12.0...v1.13.0) (2026-07-22)


### Features

* **ai:** sync model prices from the Models.dev feed with verified first-party fallback ([9b543b0](https://github.com/pentacore/media-manager/commit/9b543b044451b5b2787b6b5fca4e2f831bae1541))

# [1.12.0](https://github.com/pentacore/media-manager/compare/v1.11.1...v1.12.0) (2026-07-17)


### Bug Fixes

* **actions:** pin executors to the originating connection and sweep lost workers ([080fa1f](https://github.com/pentacore/media-manager/commit/080fa1f2bb5d810a67e66573f01890844fe6e973))
* **actions:** retry only genuinely transient executor failures ([90e865b](https://github.com/pentacore/media-manager/commit/90e865be89073e240152a84dbcaf16fca4ea2678))
* **ai:** atomic workflow resolution and safer pending-workflow claims ([b71371e](https://github.com/pentacore/media-manager/commit/b71371e4d503c557e5e0e8647f751bd738baa891))
* **ai:** bound-check LLM-written model prices ([0fab668](https://github.com/pentacore/media-manager/commit/0fab668655922190d6df74cbafd5f59d4712e4a2)), closes [A-HI#2](https://github.com/A-HI/issues/2)
* **ai:** damp DecisionAgent feedback loops ([ecef662](https://github.com/pentacore/media-manager/commit/ecef66285bd019046d710d9eb53e140fe84ae111))
* **ai:** enforce the monthly budget cap in the price refresh job ([0d6ff09](https://github.com/pentacore/media-manager/commit/0d6ff09eb5b12954b2cd6e40afb1af08d3b7e2ab)), closes [A-HI#2](https://github.com/A-HI/issues/2)
* **auth:** serialize first-user admin bootstrap across registration paths ([4fbd5d4](https://github.com/pentacore/media-manager/commit/4fbd5d459bc8f0d38b93addf6719de76205f2c1b))
* **chat:** rollback failed workflow approvals, stable message keys, SSR-safe markdown ([b6f574a](https://github.com/pentacore/media-manager/commit/b6f574a3d2024ccc368e0aaf778e74b82fe1f3c9))
* **ci:** portable migration SQL, pint style, stable tab locator ([15f934c](https://github.com/pentacore/media-manager/commit/15f934c61e8bbae18bba1a0d66636c0d67402a65))
* **db:** close data-integrity gaps and per-request hot paths ([b463bc3](https://github.com/pentacore/media-manager/commit/b463bc36da68cd7429f41e79463da406497560f3))
* **deployment:** give queue container a stop grace period above worker timeout ([bb4e929](https://github.com/pentacore/media-manager/commit/bb4e929596f2f39369dd512ed9946bd9f0cb854f))
* **octane:** scope AiSettings so the per-request mode override cannot leak ([fbef029](https://github.com/pentacore/media-manager/commit/fbef0294c845ca9dec475e11574ff316db44247a))
* **realtime:** ref-count Echo channel subscriptions and fix sidebar counters ([78c9a20](https://github.com/pentacore/media-manager/commit/78c9a20999694941b3f868b64db984fd243212e2))
* **realtime:** reseed live lists from fresh props and never drop reload events ([147fded](https://github.com/pentacore/media-manager/commit/147fded2ade74806e25b4322a4952cf071e1939c))
* **replacement:** close the residual duplicate-grab window ([34b5323](https://github.com/pentacore/media-manager/commit/34b5323813007fd42b65672a87f2c1cb24535ef9))
* **replacement:** make the sweep the true finalizer and transitions conditional ([00dc344](https://github.com/pentacore/media-manager/commit/00dc344f3bfa833431a158bae84c7d595231e8ce))
* **search:** never prune index rows because their upsert failed ([29a7319](https://github.com/pentacore/media-manager/commit/29a731939e072d425b80f9c49cdff0480b748441))
* **security:** gate SSO auto-link on the IdP email_verified claim ([1819412](https://github.com/pentacore/media-manager/commit/18194125bcef11c823d12f7369371bf5101298b9))
* **security:** harden the DecisionAgent against webhook prompt injection ([dfdbd0b](https://github.com/pentacore/media-manager/commit/dfdbd0bb4d51fc273df361e8f7eb90d5af4cc004))
* **security:** redact url query strings from service failure messages ([54c6396](https://github.com/pentacore/media-manager/commit/54c639628ffb386422c44d2be403e32746700df4))
* **security:** trust configured reverse proxies for forwarded headers ([5e9be77](https://github.com/pentacore/media-manager/commit/5e9be77a15e8f460f97eff513020a62c02a17ce9))
* **security:** validate every redirect hop in the price-fetcher web tool ([cb94a95](https://github.com/pentacore/media-manager/commit/cb94a9570503d60480b07186955d979593dcc069))
* **services:** honest intervention badge and resilient SAB history polling ([063e777](https://github.com/pentacore/media-manager/commit/063e7770cd67052b17ea19cfbdb4d6b4443acc7a))
* **services:** retry hygiene for non-idempotent calls and slow searches ([5e75aa4](https://github.com/pentacore/media-manager/commit/5e75aa4ac848285ad02d116ce1012fd4cf68ae3f))
* **ui:** remove dead Now Playing controls and scope subtitle rule errors ([6e3ecc9](https://github.com/pentacore/media-manager/commit/6e3ecc9969cedb187f34e2794319e21d451b78c9))
* **webhooks:** atomic processing claim and race-free intake dedupe ([66cd9ad](https://github.com/pentacore/media-manager/commit/66cd9ad92b8dc9d2fa6cef2cf738f5f22aa84e8a))


### Features

* **retention:** prune the fastest-growing tables on a nightly schedule ([cf009dd](https://github.com/pentacore/media-manager/commit/cf009dde767e85fe1257a82ec70938c411a63289))


### Performance Improvements

* **db:** index unindexed foreign key columns ([95ee389](https://github.com/pentacore/media-manager/commit/95ee3893e31dae627bb4accb1a3d8a7355b39d8a))

## [1.11.1](https://github.com/pentacore/media-manager/compare/v1.11.0...v1.11.1) (2026-07-16)


### Bug Fixes

* **ci:** increase browser test timeout ([0d7af8e](https://github.com/pentacore/media-manager/commit/0d7af8ed7d0a6f0eab3e4f79fe6d4a0c80ba9cab))

# [1.11.0](https://github.com/pentacore/media-manager/compare/v1.10.1...v1.11.0) (2026-07-16)


### Bug Fixes

* **media-replacement:** scope library types per Sonarr connection ([d99e6f8](https://github.com/pentacore/media-manager/commit/d99e6f8117d6c414f9bca33fc367932e8e1e08e0))
* **media-replacement:** support Sonarr subtitle downgrades ([f385a24](https://github.com/pentacore/media-manager/commit/f385a24a288e79229b5db3ae1b66610e54f2109c))


### Features

* **deployment:** add production Inertia SSR ([60037ce](https://github.com/pentacore/media-manager/commit/60037cee3900b2f88fb1b1c8379e98872a897f80))

## [1.10.1](https://github.com/pentacore/media-manager/compare/v1.10.0...v1.10.1) (2026-07-15)


### Bug Fixes

* media settings and expand browser coverage ([0194135](https://github.com/pentacore/media-manager/commit/0194135c228edafa8fd5219a496fbbd79f41d78e))

# [1.10.0](https://github.com/pentacore/media-manager/compare/v1.9.1...v1.10.0) (2026-07-15)


### Bug Fixes

* **ai:** atomic cleanup finalizer and exact pending verification predicate ([6f8dc79](https://github.com/pentacore/media-manager/commit/6f8dc791429b2b67a503090510a0a2311f7e64c1))
* **ai:** close lost-wakeup window in cleanup verification handoff ([13872b4](https://github.com/pentacore/media-manager/commit/13872b463b8d01e20e61931134d05eb352c25b16))
* **ai:** conditional resume reopen, phase-gated blocklist, accurate result status ([fffa908](https://github.com/pentacore/media-manager/commit/fffa908f81c7e161b8131cea4a40d361b754d6d2))
* **ai:** coordinate cleanup/remonitor phase and scope resumable retries precisely ([e320f74](https://github.com/pentacore/media-manager/commit/e320f74c4fa3153611f9ba6670e835e8577eefa4))
* **ai:** durable suspension state, resumable-to-trackable retry, race-safe blocklist ([30c6be0](https://github.com/pentacore/media-manager/commit/30c6be09d653aeb4db3f4aa9621b0838217e5d35))
* **ai:** harden connection pinning, grab idempotency, and monitor lifecycle ([bf0b2f7](https://github.com/pentacore/media-manager/commit/bf0b2f758ec45979b0e80e23803440b086ac083c))
* **ai:** idempotent retry, indeterminate-grab safety, and no terminal-state regression ([4a38102](https://github.com/pentacore/media-manager/commit/4a38102940a16f89c4203b633bd07ba88883e05e))
* **ai:** pin replacement to approved connection; degrade webhook tracking gracefully ([f2741d2](https://github.com/pentacore/media-manager/commit/f2741d242e0d412d94257f2c67616c58646dd010))
* **ai:** reset cleanup checkpoint on reclaim and defer mid-cleanup verification ([952b239](https://github.com/pentacore/media-manager/commit/952b2399620054debaddc72ec836bfeb635e5d99))
* **ai:** resumable retry, durable monitor restore, fresh verify, packs deferred ([dca4fe1](https://github.com/pentacore/media-manager/commit/dca4fe179e9f53cd8f46d2c1952690f42a35838a))
* **ai:** resume on unfinished cleanup (cleanup_completed_at), covering worker crashes ([1f5e275](https://github.com/pentacore/media-manager/commit/1f5e275d337f40b368d242eec41381945f99f118))
* **ai:** satisfy CI pint and typefinder type-check ([395d6ef](https://github.com/pentacore/media-manager/commit/395d6ef684a20fa3b782f4e9cad9e6fae775c215))
* **ai:** season 0, season-pack mapping, and history correlation ([5d7a73f](https://github.com/pentacore/media-manager/commit/5d7a73f49c1ab6bc84c8dd21876a31bdbfc83450))
* **ai:** suppress auto-redownload race and reconcile stuck replacements ([0d68936](https://github.com/pentacore/media-manager/commit/0d68936c9011a859804cf7762a9f9e85bb4d7539))
* **anime:** address code review findings ([243d536](https://github.com/pentacore/media-manager/commit/243d53660a45863a46ee9f9ee03c9af565a0e3cf))
* **anime:** address follow-up review findings ([015f8ec](https://github.com/pentacore/media-manager/commit/015f8ec222f7f521518df37f1448f007bd7367e9))


### Features

* **ai:** add media replacement settings domain ([a7253cd](https://github.com/pentacore/media-manager/commit/a7253cd18070cf60a202fc304f5376e8dfcbdc3f))
* **ai:** configure subtitle replacement guidance ([cd4a459](https://github.com/pentacore/media-manager/commit/cd4a459c43f22940e091149a4e91aed7a02930df))
* **ai:** execute safe media replacements ([b78b931](https://github.com/pentacore/media-manager/commit/b78b9312f066f5ccbb70cce84514bd9b8745f5f9))
* **ai:** expose subtitle replacement workflow ([8eee2dc](https://github.com/pentacore/media-manager/commit/8eee2dcb7fce43700af482957468fb2c8c936338))
* **ai:** find subtitle replacement candidates ([5089931](https://github.com/pentacore/media-manager/commit/50899311b0295824c6f44a2c6ea9b19345e1b836))
* **ai:** inspect installed media subtitles ([037844f](https://github.com/pentacore/media-manager/commit/037844fdfdf76371aed1d96d8300fd638b6480a5))
* **ai:** persist media replacement attempts ([a504b34](https://github.com/pentacore/media-manager/commit/a504b34b1009f4b87bdd47586c7f9a8b71626e88))
* **ai:** queue approval-gated media replacements ([4d0111a](https://github.com/pentacore/media-manager/commit/4d0111a9533a34bac817c5b8df7e3c9b8a848d14))
* **ai:** rank subtitle replacement releases ([f6acfa6](https://github.com/pentacore/media-manager/commit/f6acfa680578a4328d4a2dd38d0892809b545281))
* **ai:** verify replacement subtitles after import ([fb3fce3](https://github.com/pentacore/media-manager/commit/fb3fce35fe5e320bd0d9e8ce283c2684625525da))
* **anime:** seasonal anime discovery and requests ([0054ea8](https://github.com/pentacore/media-manager/commit/0054ea868fdfa3e156c606003f63585c30270a2e))
* **arr:** add native release and media file APIs ([cafbe00](https://github.com/pentacore/media-manager/commit/cafbe00af366deab82839512686383312cf0a3e5))

## [1.9.1](https://github.com/pentacore/media-manager/compare/v1.9.0...v1.9.1) (2026-07-10)

# [1.9.0](https://github.com/pentacore/media-manager/compare/v1.8.0...v1.9.0) (2026-07-10)


### Features

* **emby:** allow a user to link multiple Emby accounts (self-service) ([bbeb75f](https://github.com/pentacore/media-manager/commit/bbeb75f6f896d5277a7360f8c80f0b05870bdd46))
* **emby:** allow an admin to link multiple Emby accounts to a user ([f6abdf1](https://github.com/pentacore/media-manager/commit/f6abdf1037797e448ff41eb83c8a51d72e3971ea))
* **emby:** expose all of a user's Emby links on the profile page ([f88db5e](https://github.com/pentacore/media-manager/commit/f88db5ee9881f0e182b1d7aee51d126ff9d0f0cd))
* **emby:** list linked Emby accounts on the profile page ([a472bb9](https://github.com/pentacore/media-manager/commit/a472bb9d62c3e68f2c7dea7bc1ecab4a7d73c7e0))
* **webhooks:** add handling_status to webhook events ([6f7fdd3](https://github.com/pentacore/media-manager/commit/6f7fdd3b8eedf4ff83909494ae6c4fa63bdb73de))
* **webhooks:** handlers report a handling status ([84d6e33](https://github.com/pentacore/media-manager/commit/84d6e33e35663c5eed606d8b5add851879f67f30))
* **webhooks:** link activity log entries to webhook events ([cb157cb](https://github.com/pentacore/media-manager/commit/cb157cbf95c101fe25f7cc285d4a9c42e8eaa86e))
* **webhooks:** persist handling status when processing events ([2d2cbeb](https://github.com/pentacore/media-manager/commit/2d2cbeb6e56772416f243191288150123075b434))
* **webhooks:** show handling detail on webhook log pages ([7daccb1](https://github.com/pentacore/media-manager/commit/7daccb126c0d8216dc224354319fca45c1e9cdaf))
* **webhooks:** surface handling data on webhook log index ([c376687](https://github.com/pentacore/media-manager/commit/c3766872b16943d0946eaaa21d1070903d7b1a1b))

# [1.8.0](https://github.com/pentacore/media-manager/compare/v1.7.3...v1.8.0) (2026-07-09)


### Features

* add app:check-version command with daily schedule ([7d05b97](https://github.com/pentacore/media-manager/commit/7d05b975b91b3ab37750f23ef857370623d4134e))
* add AppVersion helper for current/latest version state ([576818c](https://github.com/pentacore/media-manager/commit/576818cb75cecf7deb66ed7a91862f2505d0a160))
* bake APP_VERSION into production images from release tag ([eb1f6da](https://github.com/pentacore/media-manager/commit/eb1f6da22036da10250de077d24875e452028f87))
* display app version in sidebar footer with update hint ([2e49ce7](https://github.com/pentacore/media-manager/commit/2e49ce7471c6b9b14b0bbdd0693729f3e9156842))
* share app version data via Inertia for authenticated users ([20b2cf6](https://github.com/pentacore/media-manager/commit/20b2cf66a1e42738bba360a381f688375ba9de8f))

## [1.7.3](https://github.com/pentacore/media-manager/compare/v1.7.2...v1.7.3) (2026-07-09)


### Bug Fixes

* **ai:** count cached read/write tokens toward free pool usage ([dc27fc6](https://github.com/pentacore/media-manager/commit/dc27fc6f6b3e75b3cd482f05fd820b8ddea10b74))

## [1.7.2](https://github.com/pentacore/media-manager/compare/v1.7.1...v1.7.2) (2026-07-09)


### Bug Fixes

* **broadcasting:** survive config:cache for reverb client config ([98abd9d](https://github.com/pentacore/media-manager/commit/98abd9df5e74f84ce0eab8504a608fb039bf2e1a))

## [1.7.1](https://github.com/pentacore/media-manager/compare/v1.7.0...v1.7.1) (2026-07-09)


### Bug Fixes

* **ui:** bind Inertia create forms to Wayfinder .form() instead of .post() ([df9fa9a](https://github.com/pentacore/media-manager/commit/df9fa9ac7aa81997c79cfd97dbf27b148432a5fc))

# [1.7.0](https://github.com/pentacore/media-manager/compare/v1.6.0...v1.7.0) (2026-07-09)


### Bug Fixes

* **tests:** use derived non-matching user id in email verification test ([23a8e97](https://github.com/pentacore/media-manager/commit/23a8e976029be1fe53208e6591efeaa95de805e8))


### Features

* **ai:** add per-pool overflow behavior for free usage pools ([d35d650](https://github.com/pentacore/media-manager/commit/d35d6503bee0db20835a4197de4b224d2e5580c0))

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
