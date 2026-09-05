<template>
    <AppLayout :breadcrumbs="breadcrumbs" full-width>
        <div class="flex h-full w-full flex-1 flex-col gap-4 p-6">

            <!-- Header -->
            <div class="flex items-start justify-between gap-4">
                <div class="flex items-center gap-3">
                    <div class="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-amber-500 to-orange-600 shadow-sm shadow-amber-500/30">
                        <ScanSearch class="h-6 w-6 text-white" />
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Algorithm Monitor</h1>
                        <p class="text-muted-foreground mt-0.5 text-sm">Verify IVAC captcha transform constants against the live JS bundle</p>
                    </div>
                </div>
            </div>

            <!-- Needs-Attention Banner (persisted across reloads by the auto-refresh job) -->
            <div v-if="engine?.needs_attention" class="rounded-lg border-2 border-amber-500 dark:border-amber-600 bg-amber-100 dark:bg-amber-950/70 overflow-hidden">
                <div class="flex items-start gap-3 px-4 py-3">
                    <AlertTriangle class="h-5 w-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-amber-900 dark:text-amber-100 text-sm">Captcha auto-refresh needs attention</div>
                        <div class="text-xs text-amber-800 dark:text-amber-200 mt-0.5">
                            A redeploy was detected but auto-extraction was unclean, so the last-known-good config was kept. Run an analysis with a BD proxy below; the banner clears on the next clean run.
                        </div>
                        <div class="text-xs text-amber-700 dark:text-amber-300 mt-1 font-mono">{{ engine.needs_attention.reason }}</div>
                        <div class="text-[11px] text-amber-600 dark:text-amber-400 mt-0.5">Since {{ engine.needs_attention.at }}</div>
                    </div>
                </div>
            </div>

            <!-- Unattended extractor repair. A structural extraction failure queues a repair
                 that the scheduler runs within ~5 minutes; a FAILED repair is the case that
                 genuinely needs a human, so it must never be log-only. -->
            <div v-if="engine?.repair?.request" class="rounded-lg border-2 border-blue-400 dark:border-blue-600 bg-blue-50 dark:bg-blue-950/50 overflow-hidden">
                <div class="flex items-start gap-3 px-4 py-3">
                    <Wrench class="h-5 w-5 text-blue-600 dark:text-blue-400 flex-shrink-0 mt-0.5" />
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-blue-900 dark:text-blue-100 text-sm">Extractor repair queued</div>
                        <div class="text-xs text-blue-800 dark:text-blue-200 mt-0.5">
                            IVAC rotated the bundle's config emission shape and the extractor could not read it. An unattended repair is queued; the scheduler picks it up within 5 minutes and it can run for ~25 minutes. Encryption keeps using the last-known-good config until it lands.
                        </div>
                        <div class="text-xs text-blue-700 dark:text-blue-300 mt-1 font-mono">
                            bundle {{ String(engine.repair.request.hash || '').slice(0, 12) }} · queued {{ engine.repair.request.queued_at }}
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="engine?.repair?.last && !engine.repair.last.repaired && engine.repair.last.stage !== 'exhausted'" class="rounded-lg border-2 border-red-500 dark:border-red-600 bg-red-50 dark:bg-red-950/50 overflow-hidden">
                <div class="flex items-start gap-3 px-4 py-3">
                    <AlertTriangle class="h-5 w-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-red-900 dark:text-red-100 text-sm">Unattended extractor repair did not apply</div>
                        <div class="text-xs text-red-800 dark:text-red-200 mt-0.5">
                            The repair was rejected at the <span class="font-semibold">{{ engine.repair.last.stage }}</span> gate and the extractor was restored to its previous state. Nothing was silently changed — but the rotation is still unfixed, so this needs a human.
                        </div>
                        <div class="text-xs text-red-700 dark:text-red-300 mt-1 font-mono">{{ engine.repair.last.detail }}</div>
                        <div class="text-[11px] text-red-600 dark:text-red-400 mt-0.5">Attempt {{ engine.repair.last.attempt }} · {{ engine.repair.last.at }}</div>
                    </div>
                </div>
            </div>

            <!-- Mid-rollout notice: IVAC selected a captcha version not yet shipped in
                 this bundle. Not a structural failure — last-known-good is retained. -->
            <div v-if="results?.extraction_alarm?.triggered && results.extraction_alarm.severity === 'rollout'" class="rounded-lg border-2 border-amber-400 dark:border-amber-600 bg-amber-50 dark:bg-amber-950/50 overflow-hidden">
                <div class="flex items-start gap-3 px-4 py-3">
                    <AlertTriangle class="h-5 w-5 text-amber-600 dark:text-amber-400 flex-shrink-0 mt-0.5" />
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-amber-900 dark:text-amber-100 text-sm">IVAC Mid-Rollout — New Captcha Version Not Yet Shipped</div>
                        <div class="text-xs text-amber-800 dark:text-amber-200 mt-0.5">
                            IVAC selected a captcha version whose module is not in the downloaded bundle yet (its own site can't encrypt these token types from this bundle). This is <span class="font-semibold">not</span> an extraction bug. Last-known-good encryption is retained — re-run Analysis once IVAC finishes deploying.
                            <span v-if="results.extraction_alarm.unaffected?.length"> Unaffected: <span class="font-semibold">{{ results.extraction_alarm.unaffected.join(', ') }}</span>.</span>
                        </div>
                        <ul class="mt-2 space-y-1">
                            <li v-for="(issue, i) in results.extraction_alarm.issues" :key="i" class="text-xs text-amber-700 dark:text-amber-300 flex items-start gap-1.5">
                                <span class="text-amber-500 mt-px">•</span>
                                <span>{{ issue }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Extraction Failure Alarm (structural — highest priority) -->
            <div v-else-if="results?.extraction_alarm?.triggered" class="rounded-lg border-2 border-red-500 dark:border-red-600 bg-red-100 dark:bg-red-950/70 overflow-hidden">
                <div class="flex items-start gap-3 px-4 py-3">
                    <AlertTriangle class="h-5 w-5 text-red-600 dark:text-red-400 flex-shrink-0 mt-0.5" />
                    <div class="flex-1 min-w-0">
                        <div class="font-bold text-red-900 dark:text-red-100 text-sm">Extraction Failed — Bundle Structure Changed</div>
                        <div class="text-xs text-red-800 dark:text-red-200 mt-0.5">
                            The analyzer could not extract the algorithm from the live bundle. This is <span class="font-semibold">not</span> a no-op — encryption may be broken. Verify the engine and re-port if needed before booking.
                        </div>
                        <ul class="mt-2 space-y-1">
                            <li v-for="(issue, i) in results.extraction_alarm.issues" :key="i" class="text-xs text-red-700 dark:text-red-300 flex items-start gap-1.5">
                                <span class="text-red-500 mt-px">•</span>
                                <span>{{ issue }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>

            <!-- Change Alert Banner -->
            <div v-if="results?.snapshot_status === 'changed'" class="rounded-lg border border-red-300 dark:border-red-700 bg-red-50 dark:bg-red-950/50 overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3">
                    <AlertTriangle class="h-5 w-5 text-red-600 dark:text-red-400 flex-shrink-0" />
                    <div class="flex-1 min-w-0">
                        <div class="font-semibold text-red-900 dark:text-red-200 text-sm">Algorithm Constants Changed!</div>
                        <div class="text-xs text-red-700 dark:text-red-300 mt-0.5 flex flex-wrap gap-3">
                            <span v-for="(change, field) in results.changes" :key="field" class="inline-flex items-center gap-1">
                                <span class="font-mono font-semibold uppercase">{{ field }}</span>:
                                <span class="line-through text-red-500">{{ truncate(String(change.old), 20) }}</span>
                                <ArrowRight class="h-3 w-3" />
                                <span class="text-red-900 dark:text-red-100 font-semibold">{{ truncate(String(change.new), 20) }}</span>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- New Entry Banner -->
            <div v-else-if="results?.snapshot_status === 'new' && !results?.previous_snapshot" class="rounded-lg border border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-950/30 overflow-hidden">
                <div class="flex items-center gap-2 px-4 py-3">
                    <CheckCircle2 class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                    <span class="text-sm text-emerald-900 dark:text-emerald-200 font-medium">First snapshot recorded — baseline established.</span>
                </div>
            </div>

            <!-- Duplicate Banner -->
            <div v-else-if="results?.snapshot_status === 'duplicate'" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900 overflow-hidden">
                <div class="flex items-center gap-2 px-4 py-3">
                    <CheckCircle2 class="h-4 w-4 text-emerald-500" />
                    <span class="text-sm text-zinc-700 dark:text-zinc-300 font-medium">No changes detected — identical to previous snapshot.</span>
                </div>
            </div>

            <!-- Sidecar health (admin only) -->
            <div v-if="isAdmin && engine" class="rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-4">
                <div class="flex items-center justify-between gap-3 flex-wrap">
                    <div class="flex items-center gap-2 min-w-0">
                        <Cpu class="h-4 w-4 text-zinc-500 flex-shrink-0" />
                        <span class="text-sm font-semibold text-zinc-900 dark:text-white">Encryption Sidecar</span>
                        <span class="text-xs text-muted-foreground hidden sm:inline">— Live JS bundle runs the site's own encrypt code</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="flex items-center gap-1.5 text-xs"
                             :class="engine.sidecar?.healthy ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'">
                            <span class="w-2 h-2 rounded-full inline-block"
                                  :class="engine.sidecar?.healthy ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                            <span class="font-semibold">{{ engine.sidecar?.healthy ? 'Healthy' : 'Down' }}</span>
                            <span v-if="engine.sidecar?.meta?.login" class="text-zinc-400 font-normal">
                                — login {{ engine.sidecar.meta.login.module }} v{{ engine.sidecar.meta.login.version }}
                                · reserve {{ engine.sidecar.meta?.reserve?.module }} v{{ engine.sidecar.meta?.reserve?.version }}
                            </span>
                        </div>
                        <button
                            @click="reloadSidecar"
                            :disabled="sidecarReloading"
                            class="inline-flex flex-shrink-0 items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 transition-colors hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        >
                            <RefreshCw class="h-3.5 w-3.5" :class="{ 'animate-spin': sidecarReloading }" />
                            Reload sidecar
                        </button>
                    </div>
                </div>
                <div v-if="engine.sidecar?.bundle_hash || engine.sidecar?.bundle_created_at" class="mt-2 font-mono text-[10px] text-muted-foreground">
                    <span v-if="engine.sidecar?.bundle_hash">bundle {{ String(engine.sidecar.bundle_hash).slice(0, 16) }}</span>
                    <span v-if="engine.sidecar?.bundle_created_at" class="ml-2 inline-flex items-center gap-1" title="Bundle download time">
                        <Clock class="h-2.5 w-2.5" />{{ formatDownloadStart(engine.sidecar.bundle_created_at) }}
                    </span>
                    <span v-if="engine.sidecar?.url" class="ml-2">· {{ engine.sidecar.url }}</span>
                </div>
            </div>

            <!-- Main column -->
            <div class="flex flex-col gap-4">

                    <!-- Analysis Input -->
                    <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                        <div class="flex items-center gap-2 px-4 py-3 border-b border-zinc-200 dark:border-zinc-800">
                            <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Run Analysis</h2>
                            <div v-if="useProxy" class="ml-auto flex items-center gap-1.5 text-[10px] text-black font-semibold bg-emerald-100 px-2 py-0.5 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 inline-block"></span>
                                Oxylabs BD Proxy
                            </div>
                            <div v-else class="ml-auto flex items-center gap-1.5 text-[10px] text-black font-semibold bg-sky-100 px-2 py-0.5 rounded-full">
                                <span class="w-1.5 h-1.5 rounded-full bg-sky-500 inline-block"></span>
                                Direct (Server IP)
                            </div>
                        </div>
                        <div class="px-4 py-4 space-y-3">
                            <!-- Connection mode toggle -->
                            <div class="flex items-center gap-1 p-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 w-fit">
                                <button
                                    @click="useProxy = false; localStorage.setItem('captcha_monitor_use_proxy', 'false')"
                                    :disabled="loading"
                                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors disabled:opacity-50"
                                    :class="!useProxy
                                        ? 'bg-white dark:bg-zinc-900 text-sky-700 dark:text-sky-300 shadow-sm'
                                        : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                                >
                                    <Server class="h-3.5 w-3.5" />
                                    Server IP (Direct)
                                </button>
                                <button
                                    @click="useProxy = true; localStorage.setItem('captcha_monitor_use_proxy', 'true')"
                                    :disabled="loading"
                                    class="flex items-center gap-1.5 px-3 py-1.5 rounded-md text-xs font-medium transition-colors disabled:opacity-50"
                                    :class="useProxy
                                        ? 'bg-white dark:bg-zinc-900 text-amber-700 dark:text-amber-300 shadow-sm'
                                        : 'text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-200'"
                                >
                                    <Globe class="h-3.5 w-3.5" />
                                    BD Proxy
                                </button>
                            </div>

                            <!-- Proxy URL input (only shown in proxy mode) -->
                            <div v-if="useProxy">
                                <label for="proxy" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1.5">
                                    Bangladeshi Proxy URL
                                </label>
                                <input
                                    id="proxy"
                                    v-model="proxyUrl"
                                    type="text"
                                    placeholder="https://customer-xxx:password@pr.oxylabs.io:7777"
                                    :disabled="loading"
                                    class="w-full px-3 py-2 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-amber-500 disabled:opacity-50 text-xs font-mono"
                                />
                                <p class="text-[11px] text-zinc-500 dark:text-zinc-400 mt-1">Requires BD IP — appointment.ivacbd.com is geo-restricted</p>
                            </div>
                            <div v-else class="text-[11px] text-sky-600 dark:text-sky-400 bg-sky-50 dark:bg-sky-950/30 rounded-md px-3 py-2 border border-sky-200 dark:border-sky-900">
                                Using this server's IP directly — no proxy needed since the server is in Bangladesh.
                            </div>

                            <div class="flex flex-wrap items-center gap-2">
                                <button
                                    @click="runAnalysis"
                                    :disabled="loading || autoRetrying"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-md bg-amber-600 hover:bg-amber-700 text-white font-medium text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                >
                                    <Loader2 v-if="loading && !autoRetrying" class="h-4 w-4 animate-spin" />
                                    <Zap v-else class="h-4 w-4" />
                                    {{ loading && !autoRetrying ? 'Downloading...' : 'Download' }}
                                </button>
                                <button
                                    @click="analyzeUntilSuccess"
                                    :disabled="loading && !autoRetrying"
                                    class="inline-flex items-center gap-2 px-4 py-2 rounded-md font-medium text-sm transition-colors disabled:opacity-50 disabled:cursor-not-allowed border"
                                    :class="autoRetrying
                                        ? 'border-red-300 dark:border-red-800 text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-950/30'
                                        : 'border-amber-300 dark:border-amber-800 text-amber-700 dark:text-amber-400 hover:bg-amber-50 dark:hover:bg-amber-950/30'"
                                >
                                    <XCircle v-if="autoRetrying" class="h-4 w-4" />
                                    <RefreshCw v-else class="h-4 w-4" />
                                    {{ autoRetrying ? `Stop Retrying (attempt ${retryAttempt})` : 'Download Until Success' }}
                                </button>
                                <label class="inline-flex items-center gap-1.5 text-xs text-zinc-600 dark:text-zinc-400">
                                    <span>Retry every</span>
                                    <input
                                        v-model.number="retryDelayMs"
                                        @change="clampRetryDelay"
                                        type="number"
                                        min="100"
                                        max="1000"
                                        step="50"
                                        :disabled="autoRetrying"
                                        class="w-20 px-2 py-1 rounded-md border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 text-zinc-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-amber-500 disabled:opacity-50 text-xs font-mono"
                                    />
                                    <span>ms</span>
                                </label>
                                <span v-if="autoRetrying && retryCountdown > 0" class="text-xs text-amber-700 dark:text-amber-400">
                                    Attempt {{ retryAttempt }} failed — retrying in {{ retryCountdown }}ms
                                </span>
                            </div>
                        </div>
                    </div>

                    <!-- Error -->
                    <div v-if="error" class="rounded-lg border border-red-200 dark:border-red-900 bg-red-50 dark:bg-red-950/30 overflow-hidden">
                        <div class="flex items-center gap-2 px-4 py-3">
                            <AlertCircle class="h-4 w-4 text-red-600 dark:text-red-400" />
                            <span class="text-sm font-semibold text-red-900 dark:text-red-200">Error</span>
                        </div>
                        <div class="px-4 pb-4 text-xs text-red-700 dark:text-red-300 font-mono">{{ error }}</div>
                    </div>

                    <!-- Results grid: Analysis Complete + Cloudflare Edge IP Race side by side (1 row each), Analysis Results spans both columns and two rows below -->
                    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

                    <!-- Progress Card -->
                    <div v-if="loading || logs.length > 0" class="rounded-lg border border-amber-300 dark:border-amber-800 bg-amber-100 dark:bg-amber-950/40 overflow-hidden lg:row-span-1">
                        <div class="flex items-center gap-2 px-4 py-3 border-b border-amber-200 dark:border-amber-900">
                            <Loader2 v-if="loading" class="h-4 w-4 animate-spin text-amber-600 dark:text-amber-400" />
                            <CheckCircle2 v-else class="h-4 w-4 text-emerald-600 dark:text-emerald-400" />
                            <h2 class="text-sm font-semibold text-amber-900 dark:text-amber-200">
                                {{ loading ? 'Running Analysis...' : 'Analysis Complete' }}
                            </h2>
                            <button
                                @click="copyLogs"
                                class="ml-auto text-xs px-2 py-0.5 rounded bg-amber-200 hover:bg-amber-300 dark:bg-amber-900 dark:hover:bg-amber-800 text-amber-900 dark:text-amber-200 transition-colors"
                            >{{ logsCopied ? 'Copied!' : 'Copy Logs' }}</button>
                        </div>
                        <div class="px-4 py-4 space-y-3">
                            <!-- Progress Steps -->
                            <div class="space-y-1.5">
                                <div v-for="(step, idx) in progressSteps" :key="idx" class="flex items-center gap-2 text-xs">
                                    <div class="flex-shrink-0 w-5 h-5 rounded-full flex items-center justify-center text-[10px] font-bold"
                                        :class="step.status === 'completed' ? 'bg-emerald-600 text-white' :
                                                step.status === 'current' ? 'bg-amber-600 text-white animate-pulse' :
                                                'bg-zinc-200 dark:bg-zinc-700 text-zinc-500'">
                                        {{ step.status === 'completed' ? '✓' : step.num }}
                                    </div>
                                    <div class="flex-1">
                                        <span class="font-medium" :class="step.status === 'completed' ? 'text-emerald-800 dark:text-emerald-200' : step.status === 'current' ? 'text-amber-900 dark:text-amber-200' : 'text-zinc-500 dark:text-zinc-400'">
                                            {{ step.title }}
                                        </span>
                                        <span v-if="step.subtitle" class="text-zinc-500 dark:text-zinc-400 ml-2">— {{ step.subtitle }}</span>
                                    </div>
                                </div>
                            </div>
                            <!-- Progress Bar -->
                            <div v-if="loading" class="space-y-1">
                                <div class="flex justify-between text-[10px] text-amber-700 dark:text-amber-300">
                                    <span>{{ Math.min(progressPercentage, 100) }}% complete</span>
                                    <span v-if="estimatedTimeRemaining">{{ estimatedTimeRemaining }} remaining</span>
                                </div>
                                <div class="w-full h-1.5 bg-amber-200 dark:bg-amber-900 rounded-full overflow-hidden">
                                    <div class="h-full bg-gradient-to-r from-amber-500 to-amber-600 transition-all duration-500 rounded-full"
                                        :style="{ width: Math.min(progressPercentage, 100) + '%' }"></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- CF edge fetch: how the bundle was obtained, plus each edge IP that was touched -->
                    <div v-if="edgeIpRace" class="rounded-lg border border-sky-300 dark:border-sky-800 bg-sky-100 dark:bg-sky-950/30 lg:row-span-1">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 px-3 sm:px-4 py-2 border-b border-zinc-100 dark:border-zinc-800">
                            <h2 class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 whitespace-nowrap">Cloudflare Edge Fetch</h2>
                            <span v-if="edgeIpRace.ok" class="text-[10px] font-medium px-1.5 py-0.5 rounded" :class="edgeFetchStrategy.class">
                                {{ edgeFetchStrategy.label }}
                            </span>
                            <span v-if="edgeIpRace.ok" class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                via <span class="font-mono text-emerald-600 dark:text-emerald-400">{{ edgeIpRace.edge_ip }}</span>
                            </span>
                            <span v-else class="text-[11px] text-amber-600 dark:text-amber-400">
                                {{ edgeIpRace.notice_active ? 'booking notice active on every edge IP' : (edgeIpRace.message || 'edge fetch failed') }}
                                — {{ useProxy ? 'fell back to BD proxy' : 'no BD proxy selected, not retried' }}
                            </span>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full min-w-[26.25rem] text-[11px]">
                                <thead>
                                    <tr class="text-left text-[10px] uppercase tracking-wide text-zinc-400 dark:text-zinc-500">
                                        <th class="px-2 sm:px-4 py-1 font-medium">Edge IP</th>
                                        <th class="px-2 sm:px-4 py-1 font-medium">Status</th>
                                        <th class="px-2 sm:px-4 py-1 font-medium">Ping</th>
                                        <th class="px-2 sm:px-4 py-1 font-medium">Result</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="row in edgeFetchRows"
                                        :key="row.edge_ip"
                                        class="border-t border-zinc-100 dark:border-zinc-800"
                                        :class="row.valid ? 'bg-emerald-50/50 dark:bg-emerald-950/20' : ''"
                                    >
                                        <td class="px-2 sm:px-4 py-1 font-mono text-zinc-800 dark:text-zinc-200 whitespace-nowrap">{{ row.edge_ip }}</td>
                                        <td class="px-2 sm:px-4 py-1 font-mono text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ row.status || '—' }}</td>
                                        <td class="px-2 sm:px-4 py-1 font-mono text-zinc-600 dark:text-zinc-400 whitespace-nowrap">{{ row.duration_ms }} ms</td>
                                        <td class="px-2 sm:px-4 py-1 whitespace-nowrap">
                                            <span v-if="row.valid" class="inline-flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-400">
                                                <CheckCircle2 class="h-3 w-3" /> valid
                                            </span>
                                            <span v-else class="inline-flex items-center gap-1 text-red-500 dark:text-red-400">
                                                <XCircle class="h-3 w-3" /> {{ row.notice_active ? 'notice' : row.error ? 'error' : 'invalid' }}
                                            </span>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Analysis Results: formal monochrome tables of everything the analyzer extracted -->
                    <div v-if="results" class="rounded-lg border border-zinc-300 dark:border-zinc-700 bg-white dark:bg-zinc-950 overflow-hidden lg:col-span-2 lg:row-span-2">
                        <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 p-4 border-b border-zinc-300 dark:border-zinc-700">
                            <h2 class="text-sm font-semibold tracking-tight text-black dark:text-white">Analysis Results</h2>
                            <code class="font-mono text-[11px] text-zinc-600 dark:text-zinc-400 break-all">{{ results.bundle_filename || results.bundle_url }}</code>
                            <div class="ml-auto flex items-center gap-3 font-mono text-[10px] text-zinc-600 dark:text-zinc-400">
                                <span v-if="results.download_started_at" class="inline-flex items-center gap-1" title="Bundle download start time">
                                    <Clock class="h-3 w-3" />{{ formatDownloadStart(results.download_started_at) }}
                                </span>
                                <span v-if="results.download_duration_ms != null" class="inline-flex items-center gap-1" title="Bundle download duration">
                                    <Timer class="h-3 w-3" />{{ formatDownloadDuration(results.download_duration_ms) }}
                                </span>
                            </div>
                        </div>

                        <!-- Login vs Reserve, side by side -->
                        <div class="overflow-x-auto border-b border-zinc-300 dark:border-zinc-700">
                            <table class="w-full min-w-[35rem] text-[11px] border-collapse">
                                <caption class="caption-top p-4 pb-2 text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">
                                    Extracted algorithm
                                </caption>
                                <thead>
                                    <tr class="border-y border-zinc-300 dark:border-zinc-700">
                                        <th scope="col" class="w-[26%] p-4 py-2 text-left font-semibold uppercase tracking-wider text-[10px] text-zinc-500 dark:text-zinc-400">Field</th>
                                        <th scope="col" class="w-[37%] p-4 py-2 text-left font-semibold uppercase tracking-wider text-[10px] text-zinc-500 dark:text-zinc-400">Login</th>
                                        <th scope="col" class="w-[37%] p-4 py-2 text-left font-semibold uppercase tracking-wider text-[10px] text-zinc-500 dark:text-zinc-400">Reserve</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in algorithmRows" :key="row.label" class="border-b border-zinc-200 dark:border-zinc-800 last:border-0 align-top">
                                        <th scope="row" class="p-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap">
                                            {{ row.label }}
                                            <span v-if="row.hint" class="block font-normal text-[10px] text-zinc-400 dark:text-zinc-500">{{ row.hint }}</span>
                                        </th>
                                        <td v-for="side in ([row.login, row.reserve] as any[])" :key="side.value + side.state" class="p-4 py-2">
                                            <div class="group flex items-start gap-2">
                                                <code class="font-mono break-all" :class="cellChipClass(side.state)">{{ side.value }}</code>
                                                <button
                                                    v-if="side.copyKey"
                                                    @click="copyToClipboard(side.copyValue, side.copyKey)"
                                                    class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity shrink-0 text-[10px] px-1.5 py-0.5 rounded border border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300"
                                                >{{ copied === side.copyKey ? '✓' : 'Copy' }}</button>
                                            </div>
                                            <div v-if="side.was" class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400">
                                                was <span class="line-through">{{ side.was }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-2 lg:divide-x divide-zinc-300 dark:divide-zinc-700">
                            <!-- Bundle-synced request constants -->
                            <div class="overflow-x-auto border-b lg:border-b-0 border-zinc-300 dark:border-zinc-700">
                                <table class="w-full text-[11px] border-collapse">
                                    <caption class="caption-top p-4 pb-2 text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">
                                        Bundle-synced constants
                                    </caption>
                                    <tbody>
                                        <tr v-for="row in constantRows" :key="row.label" class="border-t border-zinc-200 dark:border-zinc-800 align-top">
                                            <th scope="row" class="p-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap w-[42%]">{{ row.label }}</th>
                                            <td class="p-4 py-2">
                                                <div class="group flex items-start gap-2">
                                                    <code class="font-mono break-all" :class="cellChipClass(row.cell.state)">{{ row.cell.value }}</code>
                                                    <button
                                                        v-if="row.cell.copyKey"
                                                        @click="copyToClipboard(row.cell.copyValue, row.cell.copyKey)"
                                                        class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity shrink-0 text-[10px] px-1.5 py-0.5 rounded border border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300"
                                                    >{{ copied === row.cell.copyKey ? '✓' : 'Copy' }}</button>
                                                </div>
                                                <div v-if="row.cell.was" class="mt-0.5 font-mono text-[10px] text-zinc-500 dark:text-zinc-400">
                                                    was <span class="line-through">{{ row.cell.was }}</span>
                                                </div>
                                                <div v-if="row.cell.note" class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500">{{ row.cell.note }}</div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>

                            <!-- Bundle identity + timings -->
                            <div class="overflow-x-auto">
                                <table class="w-full text-[11px] border-collapse">
                                    <caption class="caption-top p-4 pb-2 text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">
                                        Bundle
                                    </caption>
                                    <tbody>
                                        <tr v-for="row in bundleRows" :key="row.label" class="border-t border-zinc-200 dark:border-zinc-800 align-top">
                                            <th scope="row" class="p-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap w-[42%]">{{ row.label }}</th>
                                            <td class="p-4 py-2">
                                                <div class="group flex items-start gap-2">
                                                    <code class="font-mono break-all" :class="cellChipClass(row.cell.state)">{{ row.cell.value }}</code>
                                                    <button
                                                        v-if="row.cell.copyKey"
                                                        @click="copyToClipboard(row.cell.copyValue, row.cell.copyKey)"
                                                        class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity shrink-0 text-[10px] px-1.5 py-0.5 rounded border border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300"
                                                    >{{ copied === row.cell.copyKey ? '✓' : 'Copy' }}</button>
                                                </div>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <!-- Extracted IVAC endpoints: the full set the bot receives over /api/config -->
                        <div v-if="endpointRows.length" class="overflow-x-auto border-t border-zinc-300 dark:border-zinc-700">
                            <table class="w-full min-w-[32.5rem] text-[11px] border-collapse">
                                <caption class="caption-top p-4 pb-2 text-left text-[10px] font-semibold uppercase tracking-[0.14em] text-zinc-500 dark:text-zinc-400">
                                    IVAC endpoints
                                    <span class="ml-1 font-normal normal-case tracking-normal text-zinc-400 dark:text-zinc-500">
                                        {{ results.endpoints?.detected_count ?? 0 }} of {{ endpointRows.length }} confirmed by this bundle
                                    </span>
                                </caption>
                                <thead>
                                    <tr class="border-y border-zinc-300 dark:border-zinc-700">
                                        <th scope="col" class="w-[26%] p-4 py-2 text-left font-semibold uppercase tracking-wider text-[10px] text-zinc-500 dark:text-zinc-400">Key</th>
                                        <th scope="col" class="p-4 py-2 text-left font-semibold uppercase tracking-wider text-[10px] text-zinc-500 dark:text-zinc-400">Value</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="row in endpointRows" :key="row.key" class="border-b border-zinc-200 dark:border-zinc-800 last:border-0 align-top">
                                        <th scope="row" class="p-4 py-2 text-left font-medium text-zinc-700 dark:text-zinc-300 whitespace-nowrap font-mono">{{ row.key }}</th>
                                        <td class="p-4 py-2">
                                            <div class="group flex items-start gap-2">
                                                <code class="font-mono break-all" :class="cellChipClass(row.cell.state)">{{ row.cell.value }}</code>
                                                <button
                                                    v-if="row.cell.copyKey"
                                                    @click="copyToClipboard(row.cell.copyValue, row.cell.copyKey)"
                                                    class="opacity-0 group-hover:opacity-100 focus:opacity-100 transition-opacity shrink-0 text-[10px] px-1.5 py-0.5 rounded border border-zinc-300 dark:border-zinc-600 text-zinc-600 dark:text-zinc-300"
                                                >{{ copied === row.cell.copyKey ? '✓' : 'Copy' }}</button>
                                            </div>
                                            <div v-if="row.cell.note" class="mt-0.5 text-[10px] text-zinc-400 dark:text-zinc-500">{{ row.cell.note }}</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    </div> <!-- end results grid -->

                    <!-- Encryption Code -->
                    <div v-if="results?.captcha_encryption" class="rounded-lg border border-zinc-300 dark:border-zinc-700 overflow-hidden">
                        <div class="flex items-center justify-between px-4 py-2.5 bg-[#252526] border-b border-zinc-700">
                            <div class="flex items-center gap-2">
                                <div class="flex gap-1">
                                    <span class="w-3 h-3 rounded-full bg-red-500/80"></span>
                                    <span class="w-3 h-3 rounded-full bg-yellow-500/80"></span>
                                    <span class="w-3 h-3 rounded-full bg-emerald-500/80"></span>
                                </div>
                                <span class="text-[11px] text-zinc-400 font-mono">captcha_encrypt.js — Token Encryption</span>
                                <span class="text-[10px] bg-purple-900/60 text-purple-300 px-1.5 py-0.5 rounded font-medium">reserve-slot</span>
                            </div>
                            <button @click="copyToClipboard(results.captcha_encryption, 'captcha_enc')" class="text-[11px] px-2 py-0.5 rounded bg-zinc-700 hover:bg-zinc-600 text-zinc-300 transition-colors">
                                {{ copied === 'captcha_enc' ? '✓ Copied' : 'Copy' }}
                            </button>
                        </div>
                        <div class="bg-[#1e1e1e] flex">
                            <div class="text-[#858585] select-none py-4 px-3 text-right font-mono text-[11px] leading-5 border-r border-zinc-700 min-w-[2.5rem]">
                                <div v-for="(_, i) in results.captcha_encryption.split('\n')" :key="i" class="h-5">{{ i + 1 }}</div>
                            </div>
                            <div class="flex-1 overflow-x-auto">
                                <pre class="font-mono text-[11px] leading-5 p-4 text-[#d4d4d4] whitespace-pre"><code v-html="highlightedCaptchaCode"></code></pre>
                            </div>
                        </div>
                    </div>

            </div>

            <!-- Bundle Versions (full-width table) -->
            <div class="rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-950 overflow-hidden">
                <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <Package class="h-4 w-4 text-zinc-500" />
                        <h2 class="text-sm font-semibold text-zinc-900 dark:text-white">Bundle Versions</h2>
                        <span v-if="versions.length" class="text-[10px] text-black bg-zinc-100 px-2 py-0.5 rounded-full font-semibold">{{ versions.length }}</span>
                    </div>
                    <button
                        @click="loadVersions"
                        :disabled="versionsLoading"
                        class="inline-flex items-center gap-1 rounded-md border border-zinc-200 px-2 py-1 text-[11px] font-medium text-zinc-700 transition-colors hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                        title="Refresh bundle versions"
                    >
                        <RefreshCw class="h-3 w-3" :class="{ 'animate-spin': versionsLoading }" />
                        Refresh
                    </button>
                </div>

                <div v-if="versionsLoading && versions.length === 0" class="p-6 flex items-center justify-center">
                    <Loader2 class="h-5 w-5 animate-spin text-zinc-400" />
                </div>

                <div v-else-if="versions.length === 0" class="p-6 text-center">
                    <Package class="h-8 w-8 text-zinc-300 dark:text-zinc-600 mx-auto mb-2" />
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">No bundles yet.<br/>Run analysis to download one.</p>
                </div>

                <div v-else class="overflow-x-auto max-h-[70vh]">
                    <table class="w-full text-left border-collapse">
                        <thead class="sticky top-0 z-10 bg-zinc-50 dark:bg-zinc-900 text-[10px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            <tr>
                                <th class="px-3 py-2 font-semibold text-center whitespace-nowrap w-[3.125rem]">S/N</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">Bundle</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">Tag</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">Login</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">Reserve</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap"><Download class="h-3 w-3 inline mr-0.5" />Download Start</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap">Download Time</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap"><Cpu class="h-3 w-3 inline mr-0.5" />Processing</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap"><RefreshCw class="h-3 w-3 inline mr-0.5" />Reload</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap"><CheckCircle2 class="h-3 w-3 inline mr-0.5" />Healthy At</th>
                                <th class="px-3 py-2 font-semibold whitespace-nowrap"><Zap class="h-3 w-3 inline mr-0.5" />Total (Download→Ready)</th>
                                <th v-if="isLoggedIn" class="px-3 py-2 font-semibold whitespace-nowrap text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800 text-[11px]">
                            <tr
                                v-for="(version, index) in versions"
                                :key="version.id"
                                class="transition-colors"
                                :class="version.is_active ? 'bg-emerald-50/50 dark:bg-emerald-950/20' : 'hover:bg-zinc-50 dark:hover:bg-zinc-900/50'"
                            >
                                <!-- S/N -->
                                <td class="px-3 py-2 align-top text-center font-mono tabular-nums text-[10px] text-zinc-400 dark:text-zinc-600">
                                    {{ index + 1 }}
                                </td>

                                <!-- Bundle -->
                                <td class="px-3 py-2 align-top max-w-[13.75rem]">
                                    <div class="flex items-center gap-1.5 font-medium text-zinc-800 dark:text-zinc-200 truncate" :title="version.bundle_filename || version.bundle_url || ''">
                                        <FileCode2 class="h-3.5 w-3.5 flex-shrink-0 text-amber-500" />
                                        <span class="truncate">{{ version.bundle_filename || version.hash_short || '—' }}</span>
                                    </div>
                                    <div class="font-mono text-[10px] text-zinc-400 truncate">{{ version.hash_short }}</div>
                                    <div class="mt-1 flex flex-wrap gap-1">
                                        <span v-if="!version.extraction_ok" class="text-[10px] bg-amber-100 text-black px-1.5 py-0.5 rounded-full font-semibold">⚠ unclean</span>
                                        <span v-if="!version.file_exists" class="text-[10px] bg-red-100 text-black px-1.5 py-0.5 rounded-full font-semibold">missing file</span>
                                    </div>
                                </td>

                                <!-- Tag -->
                                <td class="px-3 py-2 align-top whitespace-nowrap">
                                    <span v-if="version.label" class="inline-flex items-center gap-1 text-[10px] bg-blue-100 text-black px-1.5 py-0.5 rounded-full font-semibold">
                                        <Tag class="h-2.5 w-2.5" />{{ version.label }}
                                    </span>
                                    <span v-else class="text-zinc-300 dark:text-zinc-600">—</span>
                                </td>

                                <!-- Login -->
                                <td class="px-3 py-2 align-top whitespace-nowrap font-mono text-[10px]">
                                    <div class="font-semibold text-zinc-700 dark:text-zinc-300">v{{ version.login_version ?? '?' }}</div>
                                    <div class="text-zinc-400">skip={{ version.login_skip ?? '—' }} len={{ version.login_enc_len ?? '—' }}</div>
                                </td>

                                <!-- Reserve -->
                                <td class="px-3 py-2 align-top whitespace-nowrap font-mono text-[10px]">
                                    <div class="font-semibold text-zinc-700 dark:text-zinc-300">v{{ version.reserve_version ?? '?' }}</div>
                                    <div class="text-zinc-400">skip={{ version.reserve_skip ?? '—' }} len={{ version.reserve_enc_len ?? '—' }}</div>
                                </td>

                                <!-- Download start -->
                                <td class="px-3 py-2 align-top whitespace-nowrap font-mono text-[10px] text-zinc-500 dark:text-zinc-400">
                                    <template v-if="version.download_started_at">
                                        {{ formatDownloadStart(version.download_started_at) }}
                                    </template>
                                    <template v-else>
                                        {{ formatDownloadStart(version.created_at) }}
                                        <div class="text-zinc-300 dark:text-zinc-600">({{ version.created_at_human }})</div>
                                    </template>
                                </td>

                                <!-- Download duration -->
                                <td class="px-3 py-2 align-top whitespace-nowrap font-mono text-[10px] text-zinc-500 dark:text-zinc-400">
                                    {{ version.download_duration_ms != null ? formatDownloadDuration(version.download_duration_ms) : '—' }}
                                </td>

                                <!-- Processing -->
                                <td class="px-3 py-2 align-top whitespace-nowrap font-mono text-[10px] text-zinc-500 dark:text-zinc-400">
                                    {{ version.processing_duration_ms != null ? formatDownloadDuration(version.processing_duration_ms) : '—' }}
                                </td>

                                <!-- Reload (sidecar re-evaluates the bundle → becomes healthy) -->
                                <td class="px-3 py-2 align-top whitespace-nowrap font-mono text-[10px] text-zinc-500 dark:text-zinc-400" title="Sidecar reload — the Node encrypt service re-evaluating the new bundle">
                                    {{ version.reload_duration_ms != null ? formatDownloadDuration(version.reload_duration_ms) : '—' }}
                                </td>

                                <!-- Healthy at -->
                                <td class="px-3 py-2 align-top whitespace-nowrap font-mono text-[10px]">
                                    <span v-if="version.healthy_at" class="inline-flex items-center gap-0.5 text-emerald-600 dark:text-emerald-400 font-semibold">
                                        <CheckCircle2 class="h-2.5 w-2.5" />{{ version.healthy_at_human }}
                                    </span>
                                    <span v-else class="text-zinc-300 dark:text-zinc-600">not healthy yet</span>
                                </td>

                                <!-- Total ready -->
                                <td class="px-3 py-2 align-top whitespace-nowrap font-mono text-[10px] font-semibold text-zinc-600 dark:text-zinc-300">
                                    {{ version.total_ready_ms != null ? formatDownloadDuration(version.total_ready_ms) : '—' }}
                                </td>

                                <!-- Actions -->
                                <td v-if="isLoggedIn" class="px-3 py-2 align-top">
                                    <div class="flex flex-wrap items-center justify-end gap-1.5">
                                        <button
                                            @click="activateVersion(version)"
                                            :disabled="version.is_active || !version.file_exists || activatingVersionId === version.id"
                                            class="inline-flex items-center gap-1 rounded-md bg-emerald-600 hover:bg-emerald-700 px-2.5 py-1 text-[11px] font-medium text-white transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                                            title="Activate this bundle version"
                                        >
                                            <Loader2 v-if="activatingVersionId === version.id" class="h-3 w-3 animate-spin" />
                                            <RotateCcw v-else class="h-3 w-3" />
                                            {{ activatingVersionId === version.id ? 'Activating…' : (version.is_active ? 'Active' : 'Activate') }}
                                        </button>
                                        <button
                                            @click="labelVersion(version)"
                                            class="inline-flex items-center gap-1 rounded-md border border-zinc-200 px-2 py-1 text-[11px] font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                            title="Set or clear label"
                                        >
                                            <Tag class="h-3 w-3" />
                                        </button>
                                        <button
                                            @click="deleteVersion(version)"
                                            :disabled="version.is_active || deletingVersionId === version.id"
                                            class="inline-flex items-center gap-1 rounded-md border border-red-200 px-2 py-1 text-[11px] font-medium text-red-600 transition-colors hover:bg-red-50 disabled:opacity-50 disabled:cursor-not-allowed dark:border-red-900 dark:text-red-400 dark:hover:bg-red-950/40"
                                            title="Delete this bundle version"
                                        >
                                            <Loader2 v-if="deletingVersionId === version.id" class="h-3 w-3 animate-spin" />
                                            <Trash2 v-else class="h-3 w-3" />
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4">

                <!-- Recovery prompts: one card per failure mode of this pipeline (download,
                     encryption, endpoints, request constants, redeploy detection). Copy the
                     matching one and paste it to Claude Code in this repo — each prompt fixes
                     the issue, restores the portal to a working state, then adds tests. -->
                <div class="rounded-xl border border-zinc-200 bg-white overflow-hidden dark:border-zinc-800 dark:bg-zinc-900/60">
                    <div class="flex items-center gap-2.5 border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                        <div class="flex h-8 w-8 flex-shrink-0 items-center justify-center rounded-lg bg-zinc-900 dark:bg-zinc-100">
                            <Terminal class="h-4 w-4 text-white dark:text-zinc-900" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="text-sm font-semibold text-zinc-900 dark:text-white">Recovery Prompts</div>
                            <div class="text-[11px] text-zinc-500 dark:text-zinc-400">Pick the failure you are seeing, copy its prompt, paste it to Claude Code in the ipms_web repo. Each one diagnoses and fixes the issue, brings the portal back to a working state, then writes and runs tests.</div>
                        </div>
                        <div class="hidden flex-shrink-0 rounded-md bg-zinc-100 px-2 py-1 text-[10px] font-medium text-zinc-600 sm:block dark:bg-zinc-800 dark:text-zinc-400">
                            {{ recoveryPrompts.length }} scenarios
                        </div>
                    </div>

                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <div v-for="p in recoveryPrompts" :key="p.key">
                            <div class="flex items-center justify-between gap-3 px-4 py-2.5">
                                <button
                                    @click="openPrompt = openPrompt === p.key ? null : p.key"
                                    class="flex min-w-0 flex-1 items-center gap-2.5 text-left"
                                >
                                    <div class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-md border" :class="p.chip">
                                        <component :is="p.icon" class="h-3.5 w-3.5" />
                                    </div>
                                    <div class="min-w-0">
                                        <div class="text-xs font-semibold text-zinc-900 dark:text-white">{{ p.title }}</div>
                                        <div class="truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ p.when }}</div>
                                    </div>
                                </button>
                                <div class="flex flex-shrink-0 items-center gap-1">
                                    <button
                                        @click="copyToClipboard(p.prompt, p.key)"
                                        class="inline-flex items-center gap-1.5 rounded-md border border-zinc-200 bg-white px-2.5 py-1.5 text-[11px] font-medium text-zinc-700 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                    >
                                        <component :is="copied === p.key ? CheckCircle2 : Copy" class="h-3.5 w-3.5" :class="copied === p.key ? 'text-emerald-500' : ''" />
                                        {{ copied === p.key ? 'Copied' : 'Copy' }}
                                    </button>
                                    <button
                                        @click="openPrompt = openPrompt === p.key ? null : p.key"
                                        class="inline-flex h-7 w-7 items-center justify-center rounded-md text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                                        :aria-label="openPrompt === p.key ? 'Hide prompt' : 'Show prompt'"
                                    >
                                        <ChevronDown class="h-4 w-4 transition-transform" :class="openPrompt === p.key ? 'rotate-180' : ''" />
                                    </button>
                                </div>
                            </div>
                            <pre
                                v-if="openPrompt === p.key"
                                class="max-h-96 overflow-auto border-t border-zinc-100 bg-zinc-50 px-4 py-3 text-[11px] font-mono leading-relaxed whitespace-pre-wrap text-zinc-600 dark:border-zinc-800 dark:bg-zinc-950/40 dark:text-zinc-300"
                            >{{ p.prompt }}</pre>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useToast } from 'vue-toastification';
import axios from 'axios';
import hljs from 'highlight.js/lib/core';
import javascript from 'highlight.js/lib/languages/javascript';
import 'highlight.js/styles/atom-one-dark.css';
import {
    AlertCircle, AlertTriangle, ArrowRight, CheckCircle2, ChevronDown, Clock, Copy,
    Cpu, Download, FileCode2, Globe, Loader2, Package, RefreshCw, RotateCcw,
    ScanSearch, Server, Tag, Terminal, Timer, Trash2, Wrench, XCircle, Zap
} from 'lucide-vue-next';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';

hljs.registerLanguage('javascript', javascript);

const toast = useToast();
const page = usePage();
const isLoggedIn = computed(() => !!page.props.auth?.user);
const isAdmin = computed(() => (page.props.auth?.user as { role?: string } | undefined)?.role === 'super_admin');

const breadcrumbs = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Algorithm Monitor', href: '#' },
];

interface BundleVersion {
    id: number;
    bundle_filename: string | null;
    bundle_url: string | null;
    bundle_hash: string | null;
    hash_short: string | null;
    login_version: number | null;
    login_skip: number | null;
    login_enc_len: number | null;
    reserve_version: number | null;
    reserve_skip: number | null;
    reserve_enc_len: number | null;
    charset: string | null;
    extraction_ok: boolean;
    is_active: boolean;
    label: string | null;
    file_exists: boolean;
    download_started_at: string | null;
    download_started_at_human: string | null;
    download_duration_ms: number | null;
    download_completed_at: string | null;
    processing_completed_at: string | null;
    processing_duration_ms: number | null;
    healthy_at: string | null;
    healthy_at_human: string | null;
    reload_duration_ms: number | null;
    total_ready_ms: number | null;
    activated_at: string | null;
    activated_at_human: string | null;
    created_at: string | null;
    created_at_human: string | null;
}

const proxyUrl = ref(localStorage.getItem('captcha_proxy_url') || '');
const useProxy = ref(false); // false = direct (server IP), true = BD proxy
const loading = ref(false);

const error = ref<string | null>(null);
const results = ref<any>(null);
// Kept separate from `results` (rather than reading results.value?.edge_ip_race) so the
// edge-IP race log still renders on a failed analysis (e.g. proxy 403) — `results` is only
// populated on success and a v-if="results" panel further down would otherwise render a
// mostly-empty results card if we reused it for the error case.
const edgeIpRace = ref<any>(null);

// How the bundle was obtained. `direct` and `archive` are the fast paths (one sequential
// request); `race` means the fast path failed and every edge IP was hit in parallel.
const edgeFetchStrategy = computed(() => {
    switch (edgeIpRace.value?.strategy) {
        case 'archive':
            return { label: 'already archived — no download', class: 'bg-emerald-200 dark:bg-emerald-900/60 text-emerald-800 dark:text-emerald-300' };
        case 'race':
            return { label: 'raced all edge IPs (fast path failed)', class: 'bg-amber-200 dark:bg-amber-900/60 text-amber-800 dark:text-amber-300' };
        default:
            return { label: 'direct', class: 'bg-sky-200 dark:bg-sky-900/60 text-sky-800 dark:text-sky-300' };
    }
});

// Prefer the download attempts; on an archive hit nothing was downloaded, so fall back to
// the discovery attempts rather than rendering an empty table.
const edgeFetchRows = computed(() => {
    const log = edgeIpRace.value?.download_log?.length ? edgeIpRace.value.download_log : (edgeIpRace.value?.discover_log ?? []);

    return [...log].sort((a: any, b: any) => a.duration_ms - b.duration_ms);
});

const logs = ref<string[]>([]);
const logsCopied = ref(false);
const copied = ref<string | null>(null);
const analysisStartTime = ref<number | null>(null);

// Which prompt card is expanded. Collapsed by default — the copy button works without
// expanding, so the panel stays compact.
const openPrompt = ref<string | null>(null);

interface RecoveryPrompt {
    key: string;
    title: string;
    when: string;
    icon: any;
    chip: string;
    prompt: string;
}

// One prompt per failure mode of the analyzer pipeline. Each is written so Claude Code
// (1) diagnoses + fixes, (2) restores the portal to a working state, (3) then tests.
// Keep in sync with memory: feedback_captcha_debug_protocol, project_captcha_live_js_engine,
// project_dynamic_ivac_endpoints, project_bundle_synced_request_constants, kb_ivac_bundle_fetch.
const recoveryPrompts: RecoveryPrompt[] = [
{
    key: 'p_download',
    title: 'Bundle download is failing',
    when: 'Analysis errors before any extraction — edge race fails, notice page, CF block, or proxy 403.',
    icon: Download,
    chip: 'border-sky-200 bg-sky-50 text-sky-600 dark:border-sky-900 dark:bg-sky-950/50 dark:text-sky-400',
    prompt: `The Algorithm Monitor (/captcha-algorithm-monitor) cannot download the IVAC JS bundle — analysis fails before any extraction happens. Diagnose it, fix it, leave the monitor working, then cover it with tests.

Read my memory first: kb_ivac_bundle_fetch, project_captcha_live_js_engine, reference_bd_proxy.

How the download actually works — two paths, in this order:
1. PRIMARY, proxy-free CF edge fetch in PHP: app/Services/IvacEdgeBundleFetcher.php. fetchFastest() walks EDGE_IPS (last-good first, from cache key 'captcha:preferred_edge_ip'), GETs discoveryPath() = /.well-known/?cb=<random> to read the content-hashed asset name out of index.html, then GETs /assets/<name>.js on the SAME kept-alive handle. Falls back to raceDownload() (all edge IPs in parallel via curl_multi). Load-bearing options in newHandle(): CURLOPT_SSL_ENABLE_ALPN => false plus a browser UA — Cloudflare hard-blocks libcurl's TLS fingerprint without them.
2. FALLBACK, only when a BD proxy was explicitly supplied: app/Scripts/analyze_captcha_algo.py <proxy> using cloudscraper against BUNDLE_PAGE_URL (https://appointment.ivacbd.com/signin). Wired in CaptchaAlgorithmService::runScript().

Classify the failure BEFORE changing any code — three states look alike and only one is our bug:
- BOOKING NOTICE: a Cloudflare-edge 403 whose body contains "IMPORTANT NOTICE" / "APPOINTMENT BOOKING GUIDELINES", served for EVERY path including /assets/*.js and /.well-known/. IVAC is simply closed outside its booking window; the BD proxy hits the same thing. This is NOT fixable from here — confirm it, report it, and do not "fix" anything.
- WAF BLOCK: body contains "Sorry, you have been blocked" (looksLikeWafBlock). That is our TLS fingerprint / UA — check the ALPN and UA options above.
- REAL FAULT: connect timeouts or errors on every IP (stale EDGE_IPS — re-resolve appointment.ivacbd.com A and AAAA), a 200 with no <script src="/assets/*.js"> (index.html shape changed, fix extractBundleName), or a 200 body that is not JS (downloadEntry sniffs for 'var __defProp').

Reproduce against the real endpoint, do not guess. As www-data, call app(App\\Services\\IvacEdgeBundleFetcher::class)->raceDownload(fn (string $a) => null) and print ok, name, notice_active, message and every discover_log and download_log row. That output alone tells you which of the three states you are in.

After fixing, make the portal functional again — do not stop at the fetcher:
- Run CaptchaAlgorithmService::analyze('') as www-data. It must download, extract, write encrypt_meta.json, register + atomically activate the bundle version, and promote the sidecar.
- Verify the hot path, not just the return value: GET 127.0.0.1:8787/health must report ok:true and meta_coherent:true, and POST 127.0.0.1:8787/encrypt must return a token for BOTH type:login and type:reserve.
- storage/app/captcha must stay www-data:www-data 775. Run everything as www-data, never root.

Then test:
- Extend tests/Feature/Captcha/IvacEdgeBundleFetcherTest.php with a case for the exact failure you found — it overrides execute()/httpGetMulti() with canned responses, so no network is needed. Add to tests/Feature/Captcha/BundleArchiveReuseTest.php if the archive shortcut is involved.
- Run: php artisan test --compact tests/Feature/Captcha/IvacEdgeBundleFetcherTest.php
- Report which of the three states it was, what you changed, and the live /health plus /encrypt output.`,
},
{
    key: 'p_encryption',
    title: 'Captcha encryption broken / IVAC 400s',
    when: 'Extraction alarm, login_impl_match false, sidecar unhealthy, or sign-in and reserve rejecting the token.',
    icon: Cpu,
    chip: 'border-red-200 bg-red-50 text-red-600 dark:border-red-900 dark:bg-red-950/50 dark:text-red-400',
    prompt: `The captcha Algorithm Monitor (/captcha-algorithm-monitor) is showing an extraction alarm and/or IVAC is returning 400 on the encrypted captcha. IVAC likely redeployed and rotated the login and/or reserve algorithm (version -> module, secret, skip/enc_len can all change). Fix it fast, restore encryption, then lock it in with a regression test. Do NOT front-load reading the analyzer source — the LIVE data diagnoses this in seconds.

First read my memory: project_captcha_live_js_engine, feedback_captcha_debug_protocol, kb_captcha_algorithm_verification, feedback_captcha_storage_perms.

Key files:
- app/Scripts/captcha_live_runtime.cjs  (exposes encrypt modules + wellformedReason canary; used by BOTH the sidecar and the analyzer probe)
- app/Scripts/analyze_captcha_algo.py   (live-bundle analyzer; _wellformed_output canary; _enclosing_computed_method/_match_paren_back secret walk; ANALYSIS_CACHE_VERSION, currently v4)
- app/Scripts/captcha_encrypt_server.cjs (sidecar; systemd ipms-captcha-encrypt; 127.0.0.1:8787; endpoints /encrypt /health /reload /stage /promote)
- app/Services/CaptchaAlgorithmService.php (analyze/heal/attribution/alarm; syncReserveSlotId; needsAttention/engineStatus)
- app/Services/Captcha/CaptchaBundleVersionService.php (register + atomic activate/rollback; mirrors bundle+meta and promotes the sidecar)
- tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php (regression corpus of past bundles)

Do these first-moves IN ORDER:
1. chown -R www-data:www-data storage/app/captcha   (anything run as root — a manual analyze, a root-run scheduled job — re-owns these files root:root and then aborts www-data runs with "Permission denied"; re-chown if a run dies on perms).
2. Run the live diagnosis as www-data: CaptchaAlgorithmService::analyze('')  (edge fetch is proxy-free). Dump: error, bundle_url, extraction_alarm, encrypt_meta, live_modules, login_impl_match, reserve_impl_match, detected_login_*, detected_offset/length, live_login_output, logs. This alone reveals which type failed + its new module/version/skip/enc_len/secret + the "[GT] wellformed={...}" verdict.
3. Two common failure signatures — tell them apart from the analyze() output:
   a) A module ran but wellformed=false: run that module directly via captcha_live_runtime.cjs (patchBundle -> vm -> registry[module](token,secret,skip,enc_len)) and inspect the RAW output + wellformedReason. A "." or ":" inside the transform window (happens when skip < 2) or a changed output alphabet is a CANARY FALSE-REJECT, not a broken algorithm — fix the canary to let a non-alphabet INPUT char pass through unchanged while still requiring alphabet chars to map back into the alphabet (mirror the fix in BOTH captcha_live_runtime.cjs::wellformedReason and analyze_captcha_algo.py::_wellformed_output).
   b) "No encrypt modules exposed" / "no call sites" / a null secret: the analyzer's structural walk broke on new bundle shaping (config trapped in a computed static member, strings containing braces/quotes in the secret concat). Fix _enclosing_computed_method / _match_paren_back in analyze_captcha_algo.py to stay string-aware while walking — do NOT re-port anything to PHP.
   If encrypt_meta[type].version is absent from dispatch_versions -> it's an IVAC mid-rollout (amber banner), keep last-known-good and re-run later (not our bug).
4. After ANY edit to a .cjs or the Python analyzer/canary: clear storage/app/captcha/analysis_cache/* AND bump ANALYSIS_CACHE_VERSION (the content-addressed cache memoizes probe output and will silently serve the pre-fix result, masking your fix).
5. After editing a .cjs: systemctl restart ipms-captcha-encrypt (a /reload or /promote reuses the OLD in-memory canary/module closure; a staged bundle captured pre-fix code).
6. VERIFY THE HOT PATH, not just tests: curl -X POST 127.0.0.1:8787/encrypt for BOTH type:login and type:reserve must return a token (not 500). Then re-run CaptchaAlgorithmService::analyze('') to heal (clean -> writes encrypt_meta, atomic activate via CaptchaBundleVersionService + sidecar promote, syncs reserve_slot_id, clears needs_attention).
7. Add the new bundle (storage/app/captcha/bundles/<hash>.js) to CaptchaAlgorithmCorpusTest as a regression case, describing the shape that broke it. Run: php artisan test --compact tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php, then also tests/Feature/Captcha/CaptchaAlgorithmGroundTruthTest.php and tests/Feature/Captcha/CaptchaEncryptionServiceTest.php. Then update memory.

Run everything as www-data (never root — root-owned files in storage/app/captcha break the web/sidecar path). The repo has a PostToolUse hook that chowns+chmods+builds after edits. Finish by reporting: which type rotated, its new module/version/skip/enc_len, and the live /encrypt output for both types.`,
},
{
    key: 'p_endpoints',
    title: 'IVAC endpoints not fully extracted',
    when: 'The Extracted IVAC Endpoints table is missing keys, shows a stale path, or the bot 404s on a rotated route.',
    icon: Globe,
    chip: 'border-amber-200 bg-amber-50 text-amber-600 dark:border-amber-900 dark:bg-amber-950/50 dark:text-amber-400',
    prompt: `The Algorithm Monitor's "Extracted IVAC Endpoints" table is wrong — a path is missing, stale, or the bot is 404ing on a route IVAC rotated. Fix the extraction, get the correct endpoints delivered to the bot, then test it.

Read my memory first: project_dynamic_ivac_endpoints, project_bundle_synced_request_constants.

How this pipeline works:
- EXTRACT: app/Scripts/extract_request_constants.cjs executes the bundle and emits {ok, paymentConfigId, reserveRequestMeta, reserveSlotId, endpoints{...}}. Strategy A records module-scope axios calls (yields signin, bookingConfig, getBookingConfig, reserveSlot, and the x-sec-navigation-state header off the sign-in call); plain-literal fallbacks cover verifyOtp and uploadFile and also back up the Strategy-A keys; payment uses the fixed dg-epay/initiate template. Every path is well-formedness-gated (must start with "/" and carry its stable anchor) before it is emitted — a failing key is OMITTED so the bot keeps its compiled-in default.
- SYNC: CaptchaAlgorithmService::syncEndpoints() merges only well-formed values into settings.ivac_endpoints (last-known-good on failure), called from analyze() sharing one memoized Node run with syncRequestConstants().
- DELIVER: app/Http/Controllers/Api/PublicConfigController.php AND app/Services/ConfigExportService.php both emit "endpoints" — they must stay in sync.
- CONSUME: Java AppConfig typed getters (getSigninPath(), getSigninNavState(), getUploadRuntimeState(), ...), each falling back to a compiled-in default.

Know what is EXPECTED to be absent before you "fix" it: there are 10 keys, and the extractor can only produce 8. sendOtp and uploadRuntimeState (x-sec-runtime-state) are obfuscated component-local concats whose value depends on a runtime variable — they are NOT headless-decodable by design and keep their seeded default or a manual override from /ivac-endpoints. A missing sendOtp or uploadRuntimeState is correct behaviour, not a bug. The other 8 are: signin, verifyOtp, uploadFile, bookingConfig, getBookingConfig, reserveSlot, payment, signinNavState.

Diagnose with the real extractor, not by reading regexes:
- node app/Scripts/extract_request_constants.cjs storage/app/captcha/ivac-bundle.js  (cold run ~3s) and compare its endpoints{} against the 8 above.
- If a key is missing, find why it was gated out: the axios record missed it (the axios instance name is minified and rotates — a past outage was an unescaped "$" in the matching regexes when it minified to "\$h"), or the well-formedness anchor no longer matches the new path. Fix the extractor so the key is emitted, and keep the gate — never let a malformed value through, because a bad path is worse than a stale one.

Then make it functional end to end:
- Run CaptchaAlgorithmService::analyze('') as www-data so settings.ivac_endpoints is re-synced, and confirm the monitor's endpoints table shows the corrected value.
- Confirm delivery: GET /api/config with a slot Bearer token must carry the corrected "endpoints" object, and ConfigExportService must emit the same. If you touched the shape, check the Java AppConfig getter and bump BotVersion (pattern duronto_v_X.Y) — a Java behaviour change needs a JAR rebuild via the portal button, never mvn package as root.
- /ivac-endpoints is the manual override page — verify the value is editable and persists there.

Then test:
- Add or extend: tests/Feature/Captcha/RequestConstantsExtractorTest.php (extraction), tests/Feature/IvacEndpointsConfigTest.php (sync + /api/config delivery), tests/Feature/IvacEndpointControllerTest.php (the override page).
- Run: php artisan test --compact tests/Feature/IvacEndpointsConfigTest.php tests/Feature/Captcha/RequestConstantsExtractorTest.php
- Report which keys the bundle now yields, which are intentionally defaulted, and what the bot receives over /api/config.`,
},
{
    key: 'p_constants',
    title: 'reserveSlotId / paymentConfigId wrong',
    when: 'Reserve 404s or payment initiate fails — the bundle-synced UUIDs or x-v-request-meta are stale or misdetected.',
    icon: Tag,
    chip: 'border-violet-200 bg-violet-50 text-violet-600 dark:border-violet-900 dark:bg-violet-950/50 dark:text-violet-400',
    prompt: `The bundle-synced request constants are wrong — reserve-slot or dg-epay payment initiate is failing because reserve_slot_id, payment_config_id or reserve_request_meta is stale or misdetected. Fix the sync, get the correct values to the bot, then test.

Read my memory first: project_bundle_synced_request_constants, project_reserve_slot_appointment_dates, project_dynamic_ivac_endpoints.

What these are and how each is obtained (they use TWO different mechanisms — check the right one):
- reserve_slot_id: the UUID in POST /slots/{id}/reserve-slot. It is a DEPLOYMENT-SCOPED CONSTANT in IVAC's frontend bundle, NOT the account's appointmentId — never "fix" it by substituting an appointment ID. It is a plain literal, so CaptchaAlgorithmService::syncReserveSlotId() regex-matches it straight out of storage/app/captcha/ivac-bundle.js.
- payment_config_id: the UUID in POST /payment/{id}/dg-epay/initiate. RC4-obfuscated, so it CANNOT be regexed — app/Scripts/extract_request_constants.cjs executes the bundle's own builders and records the axios call; CaptchaAlgorithmService::syncRequestConstants() persists it.
- reserve_request_meta: the x-v-request-meta header value sent on reserve-slot. Same obfuscated path as payment_config_id.

All three are best-effort by design: a failed extraction leaves the last-known-good value untouched so a brittle run never blanks a working constant. That means a STALE value looks identical to a successful no-op — always compare against the live bundle before concluding the sync is broken.

Diagnose:
- node app/Scripts/extract_request_constants.cjs storage/app/captcha/ivac-bundle.js and read paymentConfigId, reserveRequestMeta, reserveSlotId.
- Compare to the settings row: reserve_slot_id, payment_config_id, reserve_request_meta.
- Also grep the bundle directly for the reserve-slot UUID pattern to confirm what syncReserveSlotId() should have matched.
- If the extractor returns ok:false or a null field, the bundle shaping changed (a minified identifier rotated, or the URL moved into a local object map — both have happened). Fix the extractor's recording/matching, keeping the "omit rather than emit garbage" behaviour.

Then make it functional:
- Run CaptchaAlgorithmService::analyze('') as www-data — it calls syncReserveSlotId() and syncRequestConstants() and updates the settings row; the monitor's "Bundle-synced request constants" table should then show detected == previous with changed=true on the run that healed it.
- Confirm delivery to the bot: GET /api/config must carry reserveSlotId, paymentConfigId and reserveRequestMeta, and ConfigExportService must emit the same values (keep both in sync).
- If any Java call site needed changing, bump BotVersion (duronto_v_X.Y) and rebuild the JAR via the portal button — never mvn package as root.

Then test:
- Extend tests/Feature/Captcha/RequestConstantsExtractorTest.php, tests/Feature/Captcha/RequestConstantsCacheTest.php and tests/Feature/Captcha/ConstantSyncReportingTest.php (the last one covers the detected/previous/changed reporting the monitor renders).
- Run: php artisan test --compact tests/Feature/Captcha/RequestConstantsExtractorTest.php tests/Feature/Captcha/ConstantSyncReportingTest.php
- Report the old vs new value of each of the three constants and confirm what /api/config now serves.`,
},
{
    key: 'p_autorefresh',
    title: 'Redeploys not detected automatically',
    when: 'The bundle is days old and no analysis ran by itself — scheduled auto-refresh is not healing after a redeploy.',
    icon: RefreshCw,
    chip: 'border-emerald-200 bg-emerald-50 text-emerald-600 dark:border-emerald-900 dark:bg-emerald-950/50 dark:text-emerald-400',
    prompt: `The Algorithm Monitor is not self-healing: IVAC redeployed but no analysis ran on its own, so the sidecar is still encrypting with a stale bundle. Find why the scheduled auto-refresh is not firing, fix it, heal the current state, then test.

Read my memory first: reference_scheduler, project_server_migration_2026-07, project_captcha_live_js_engine.

The chain, top to bottom — check every link, several have silently broken before:
1. Root crontab must contain: * * * * * cd /var/www/html/ipms_web && /usr/bin/php8.4 artisan schedule:run. The "cd" is load-bearing, and this line has been left COMMENTED OUT after a server migration — verify with crontab -l, do not assume.
2. routes/console.php registers: Schedule::command('captcha-algorithm:auto-refresh')->everyFiveMinutes()->withoutOverlapping(). Check php artisan schedule:list shows it with a sane next-run.
3. app/Console/Commands/RefreshCaptchaAlgorithmCommand.php then applies three early exits, and each one returns SUCCESS silently:
   - captcha_bd_proxy_url empty -> warns and exits.
   - inBookingWindow() true -> skips, to avoid a mid-race sidecar reload. This compares Dhaka time against settings.window_start_time/window_end_time, so a 24-hour window (00:00:00 to 23:59:59) makes it skip FOREVER. Check the actual settings values before touching the command.
   - bundle asset unchanged vs cache key 'captcha:last_bundle_asset' -> exits as "no redeploy". Note that marker only advances on a CLEAN apply, and a cache flush leaves it null.
4. The cheap probe itself: analyze_captcha_algo.py <proxy> --head-only, over cloudscraper via the BD proxy. It returns null on any proxy/CF failure and the command treats that as transient.

Run the command by hand as www-data and read exactly which of those branches it takes — that is the diagnosis. Then fix the actual broken link. If the booking-window guard is the problem, the intent is to skip only the real race window, so make the guard reflect that intent rather than deleting it (a full-day window must not mean "never refresh"), and say clearly which settings values you relied on.

Then make it functional:
- Heal the current state now: run CaptchaAlgorithmService::analyze('') as www-data so the live bundle is downloaded, extracted, activated and promoted.
- Verify: GET 127.0.0.1:8787/health shows ok:true, meta_coherent:true and a bundle_hash matching the newest row in captcha_bundle_versions; POST /encrypt returns tokens for type:login and type:reserve.
- Confirm the loop now runs unattended: after the fix, php artisan schedule:list plus one real cron tick, and check the command logs a genuine decision rather than an early exit.
- Keep storage/app/captcha as www-data:www-data 775.

Then test:
- There is currently NO test for RefreshCaptchaAlgorithmCommand — write tests/Feature/Captcha/CaptchaAutoRefreshCommandTest.php covering: skips inside a real booking window, does NOT skip when the window spans the whole day, exits on an unchanged asset, and runs the analyzer when the asset changed (mock CaptchaAlgorithmService and the asset probe — no network).
- Run: php artisan test --compact tests/Feature/Captcha/CaptchaAutoRefreshCommandTest.php
- Report which link was broken, what you changed, and proof the next redeploy will now be picked up automatically.`,
},
];
let pollInterval: ReturnType<typeof setInterval> | null = null;

const versions = ref<BundleVersion[]>([]);
const activeVersion = ref<BundleVersion | null>(null);
const versionsLoading = ref(false);
const activatingVersionId = ref<number | null>(null);
const deletingVersionId = ref<number | null>(null);

const engine = ref<any>(null);
const sidecarReloading = ref(false);

const loadEngine = async () => {
    if (!isAdmin.value) { return; }
    try {
        const res = await axios.get('/api/captcha-algorithm/engine');
        engine.value = res.data;
        // Prefer the server-stored BD proxy (shared with the scheduled auto-refresh job)
        // when the browser has none saved yet, so both use one source.
        if (engine.value?.bd_proxy_url && !localStorage.getItem('captcha_monitor_proxy_v3')) {
            proxyUrl.value = engine.value.bd_proxy_url;
        }
    } catch (e: any) {
        // silent — engine panel just won't render
    }
};

const reloadSidecar = async () => {
    if (!isLoggedIn.value) { toast.error('Log in to reload the sidecar'); return; }
    sidecarReloading.value = true;
    try {
        await axios.post('/api/captcha-algorithm/sidecar/reload');
        await loadEngine();
        toast.success('Sidecar reloaded');
    } catch (e: any) {
        toast.error('Failed to reload sidecar');
    } finally {
        sidecarReloading.value = false;
    }
};

onMounted(() => {
    const saved = localStorage.getItem('captcha_monitor_proxy_v3');
    if (saved) proxyUrl.value = saved;
    const savedMode = localStorage.getItem('captcha_monitor_use_proxy');
    if (savedMode !== null) useProxy.value = savedMode === 'true';
    loadEngine();
    loadVersions();
});

onBeforeUnmount(() => {
    autoRetrying.value = false;
    if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
    }
});

const applyVersionsPayload = (payload: { active: BundleVersion | null; versions: BundleVersion[] } | undefined | null) => {
    if (!payload) { return; }
    versions.value = payload.versions ?? [];
    activeVersion.value = payload.active ?? null;
};

const loadVersions = async () => {
    versionsLoading.value = true;
    try {
        const res = await axios.get('/api/captcha-algorithm/versions');
        applyVersionsPayload(res.data);
    } catch (e: any) {
        toast.error('Failed to load bundle versions');
    } finally {
        versionsLoading.value = false;
    }
};

const activateVersion = async (version: BundleVersion) => {
    if (!isLoggedIn.value) { toast.error('Log in to activate a bundle version'); return; }
    if (version.is_active || !version.file_exists) { return; }
    if (!confirm('Activate this bundle version? This reloads the live captcha encryption (~1s).')) { return; }
    activatingVersionId.value = version.id;
    try {
        const res = await axios.post(`/api/captcha-algorithm/versions/${version.id}/activate`);
        if (res.data.success) {
            toast.success(res.data.message);
            await loadVersions();
        } else {
            toast.error(res.data.message ?? 'Activation failed');
        }
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Activation failed');
    } finally {
        activatingVersionId.value = null;
    }
};

const labelVersion = async (version: BundleVersion) => {
    if (!isLoggedIn.value) { toast.error('Log in to label a bundle version'); return; }
    const next = window.prompt('Set a label for this bundle version (leave blank to clear):', version.label ?? '');
    if (next === null) { return; }
    const trimmed = next.trim();
    try {
        await axios.patch(`/api/captcha-algorithm/versions/${version.id}`, { label: trimmed === '' ? null : trimmed });
        toast.success(trimmed === '' ? 'Label cleared' : 'Label updated');
        await loadVersions();
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Failed to update label');
    }
};

const deleteVersion = async (version: BundleVersion) => {
    if (!isLoggedIn.value) { toast.error('Log in to delete a bundle version'); return; }
    if (version.is_active) { return; }
    if (!confirm('Delete this bundle version? This cannot be undone.')) { return; }
    deletingVersionId.value = version.id;
    try {
        await axios.delete(`/api/captcha-algorithm/versions/${version.id}`);
        toast.success('Bundle version deleted');
        await loadVersions();
    } catch (e: any) {
        toast.error(e?.response?.data?.message ?? 'Failed to delete bundle version');
    } finally {
        deletingVersionId.value = null;
    }
};

const pollProgress = async () => {
    if (!loading.value) return;
    try {
        const res = await axios.get('/api/captcha-algorithm/progress');
        if (res.data?.logs?.length > 0) {
            logs.value = res.data.logs;
        }
    } catch (e: any) {
        // silent
    }
};

const progressSteps = computed(() => {
    const steps = [
        { num: 1, title: 'CloudScraper session', subtitle: '' },
        { num: 2, title: 'Fetching appointment.ivacbd.com', subtitle: '' },
        { num: 3, title: 'Scanning for JS bundle URL', subtitle: '' },
        { num: 4, title: 'Downloading JS bundle', subtitle: '' },
        { num: 5, title: 'Verifying algorithm structure', subtitle: '' },
        { num: 6, title: 'Extracting constants', subtitle: '' },
    ] as any[];

    let maxCompletedStep = -1;
    steps.forEach((step) => {
        const stepPattern = `[${step.num}/6]`;
        const stepLogs = logs.value.filter(l => l.includes(stepPattern));
        if (stepLogs.length > 0) {
            const last = stepLogs[stepLogs.length - 1];
            step.status = (last.includes('✓') || last.includes('✗') || last.includes('⚠')) ? 'completed' : 'current';
            if (step.status === 'completed') maxCompletedStep = Math.max(maxCompletedStep, step.num);
            step.subtitle = last.replace(stepPattern, '').replace(/^[✓✗⚠]\s*/, '').trim();
        } else if (maxCompletedStep >= step.num) {
            step.status = 'completed';
        } else if (logs.value.length > 0 && maxCompletedStep >= step.num - 1) {
            step.status = 'current';
        } else {
            step.status = 'pending';
        }
    });
    return steps;
});

const progressPercentage = computed(() => {
    const done = progressSteps.value.filter(s => s.status === 'completed').length;
    return Math.round((done / 6) * 100);
});

const estimatedTimeRemaining = computed(() => {
    if (!analysisStartTime.value || !loading.value) return '';
    const elapsedMs = Date.now() - analysisStartTime.value;
    const completed = progressSteps.value.filter(s => s.status === 'completed').length;
    if (completed === 0) return elapsedMs < 30000 ? '~3 min' : '';
    const msPerStep = elapsedMs / completed;
    const rem = msPerStep * (6 - completed);
    if (rem < 1000) return '<1s';
    if (rem < 60000) return `${Math.round(rem / 1000)}s`;
    return `${Math.round(rem / 60000)}m ${Math.round((rem % 60000) / 1000)}s`;
});

/**
 * Row model for the Analysis Results tables. Everything the analyzer extracted is
 * enumerated here rather than in the template, so a new extracted field is one line.
 *
 * `state` is deliberately not a colour: the tables are monochrome, so a mismatch is
 * signalled by an inverted chip and a struck-through previous value instead.
 */
type CellState = 'ok' | 'changed' | 'unconfirmed' | 'stored' | 'unknown' | 'plain';

interface Cell {
    value: string;
    was?: string | null;
    note?: string | null;
    state: CellState;
    copyKey?: string;
    copyValue?: string | null;
}

const plainCell = (value: unknown, copyKey?: string): Cell => ({
    value: value === null || value === undefined || value === '' ? '—' : String(value),
    state: 'plain',
    copyKey: value ? copyKey : undefined,
    copyValue: value ? String(value) : null,
});

/** A detected value compared against what we had stored. */
const comparedCell = (detected: unknown, stored: unknown, match: boolean | null, copyKey?: string): Cell => {
    const shown = detected ?? stored;

    return {
        value: shown === null || shown === undefined || shown === '' ? '—' : String(shown),
        was: match === false && stored !== null && stored !== undefined ? String(stored) : null,
        state: match === true ? 'ok' : match === false ? 'changed' : 'unknown',
        copyKey: shown ? copyKey : undefined,
        copyValue: shown ? String(shown) : null,
    };
};

const flagCell = (ok: boolean | null | undefined): Cell => ({
    value: ok === true ? 'present' : ok === false ? 'missing' : 'unknown',
    state: ok === true ? 'ok' : ok === false ? 'changed' : 'unknown',
});

const algorithmRows = computed((): Array<{ label: string; hint?: string; login: Cell; reserve: Cell }> => {
    const r = results.value;
    if (!r) return [];
    const meta = r.encrypt_meta ?? {};

    return [
        { label: 'Module', hint: 'encryptText export used', login: plainCell(meta.login?.module), reserve: plainCell(meta.reserve?.module) },
        { label: 'Version', hint: 'config version dispatched', login: plainCell(meta.login?.version), reserve: plainCell(meta.reserve?.version) },
        {
            label: 'Offset', hint: 'skip',
            login: comparedCell(r.detected_login_skip, r.db_login_skip, r.login_offset_match),
            reserve: comparedCell(r.detected_offset, r.php_offset, r.offset_match),
        },
        {
            label: 'Length', hint: 'enc_len',
            login: comparedCell(r.detected_login_enc_len, r.db_login_enc_len, r.login_length_match),
            reserve: comparedCell(r.detected_length, r.php_length, r.length_match),
        },
        {
            label: 'Charset', hint: 'target alphabet',
            login: comparedCell(r.detected_charset, r.php_charset, r.login_charset_match, 'login_charset'),
            reserve: comparedCell(r.detected_charset, r.php_charset, r.charset_match, 'charset'),
        },
        {
            label: 'Secret',
            login: comparedCell(r.detected_login_secret, r.active_login_seed, r.login_seed_match, 'login_secret'),
            reserve: comparedCell(r.detected_secret, r.active_seed, r.seed_match, 'secret'),
        },
        { label: 'Fingerprint', hint: 'algorithm markers', login: flagCell(r.login_magic_match), reserve: flagCell(r.magic_numbers_match) },
        {
            label: 'Live output', hint: 'ground truth on the test token',
            login: plainCell(r.live_login_output, 'live_login'),
            reserve: plainCell(r.live_reserve_output, 'live_reserve'),
        },
    ];
});

const bundleRows = computed((): Array<{ label: string; cell: Cell }> => {
    const r = results.value;
    if (!r) return [];
    const active = r.bundle_versions?.active ?? {};
    const ms = (v: unknown) => (v === null || v === undefined ? null : formatDownloadDuration(Number(v)));

    return [
        { label: 'Asset', cell: plainCell(active.bundle_filename ?? r.bundle_filename) },
        { label: 'SHA-256', cell: plainCell(r.encrypt_meta?.bundle_hash ?? active.bundle_hash, 'bundle_hash') },
        { label: 'Source', cell: plainCell(active.bundle_url ?? r.bundle_url) },
        { label: 'Fetched', cell: plainCell(r.download_started_at ? formatDownloadStart(r.download_started_at) : null) },
        { label: 'Download', cell: plainCell(ms(r.download_duration_ms)) },
        { label: 'Processing', cell: plainCell(ms(active.processing_duration_ms)) },
        { label: 'Sidecar reload', cell: plainCell(ms(active.reload_duration_ms)) },
        { label: 'Total ready', cell: plainCell(ms(active.total_ready_ms)) },
        { label: 'Encrypt modules', cell: plainCell((r.live_modules ?? []).join(', ')) },
        { label: 'Extraction', cell: flagCell(active.extraction_ok ?? null) },
    ];
});

/**
 * A bundle constant. Three distinct outcomes the operator must be able to tell apart:
 * extracted from THIS bundle (ok / changed), not extractable but a stored value is still
 * in force (stored), or nothing at all (unknown). Showing "—" for the stored case hides
 * the value the bot is actually sending.
 */
const syncedCell = (c: any, copyKey: string): Cell => {
    const detected = c?.detected ?? null;
    const previous = c?.previous ?? null;

    if (detected) {
        return {
            value: String(detected),
            was: c?.changed && previous ? String(previous) : null,
            note: c?.changed ? 'updated from this bundle' : 'confirmed by this bundle',
            state: c?.changed ? 'changed' : 'ok',
            copyKey,
            copyValue: String(detected),
        };
    }

    if (previous) {
        // These three ARE meant to be extractable, so a miss is a regression in the
        // extractor (or a bundle shape it does not handle) — not a normal outcome. Say so,
        // because the stored value may now be stale against a rotated deployment.
        return {
            value: String(previous),
            note: 'NOT found in this bundle — stored value still in use, may be stale',
            state: 'unconfirmed',
            copyKey,
            copyValue: String(previous),
        };
    }

    return { value: '—', state: 'unknown' };
};

/** Obfuscated component-local concats that are never headless-decodable, by design. */
const NEVER_EXTRACTABLE = new Set(['sendOtp', 'uploadRuntimeState']);

const constantRows = computed((): Array<{ label: string; cell: Cell }> => {
    const r = results.value;
    if (!r) return [];
    const rc = r.request_constants ?? {};

    return [
        { label: 'Reserve Slot ID', cell: syncedCell(r.reserve_slot_id, 'reserve_slot_id') },
        { label: 'Payment Config ID', cell: syncedCell(rc.payment_config_id, 'payment_config_id') },
        { label: 'Reserve Request Meta', cell: syncedCell(rc.reserve_request_meta, 'reserve_request_meta') },
    ];
});

/**
 * Every endpoint the bot will receive over /api/config, marked with whether this bundle
 * confirmed it. sendOtp and uploadRuntimeState are obfuscated component-local concats that
 * are never headless-extractable, so they legitimately stay on their stored value.
 */
const endpointRows = computed((): Array<{ key: string; cell: Cell }> => {
    const e = results.value?.endpoints;
    if (!e) return [];
    const detected = e.detected ?? {};
    const changed = e.changed ?? {};
    const effective = e.effective ?? {};

    return Object.keys(effective).map((key) => {
        const value = String(effective[key] ?? '');
        const wasChanged = Object.prototype.hasOwnProperty.call(changed, key);
        const byDesign = NEVER_EXTRACTABLE.has(key);

        let note: string;
        let state: CellState;
        if (wasChanged) {
            note = 'updated from this bundle';
            state = 'changed';
        } else if (detected[key]) {
            note = 'confirmed by this bundle';
            state = 'ok';
        } else if (byDesign) {
            note = 'stored — not extractable by design';
            state = 'stored';
        } else {
            note = 'NOT found in this bundle — stored value may be stale';
            state = 'unconfirmed';
        }

        return {
            key,
            cell: {
                value: value || '—',
                note,
                state,
                copyKey: value ? `endpoint_${key}` : undefined,
                copyValue: value || null,
            } as Cell,
        };
    });
});

/** Monochrome chip: mismatches invert instead of turning red. */
const cellChipClass = (state: CellState): string => {
    if (state === 'changed') return 'bg-black text-white dark:bg-white dark:text-black font-semibold px-1.5 py-0.5 rounded-sm';
    if (state === 'unknown') return 'border border-dashed border-zinc-400 dark:border-zinc-600 text-zinc-500 dark:text-zinc-400 px-1.5 py-0.5 rounded-sm';
    // A value that SHOULD have been extracted but was not: outlined so it reads as
    // "in use but unverified against this deployment", distinct from a plain confirmed value.
    if (state === 'unconfirmed') return 'border border-dashed border-zinc-500 dark:border-zinc-500 text-zinc-800 dark:text-zinc-200 px-1.5 py-0.5 rounded-sm';
    // `stored` is a real value in force that this bundle was never expected to confirm.
    if (state === 'stored') return 'text-zinc-700 dark:text-zinc-300';

    return 'text-zinc-900 dark:text-zinc-100';
};

const highlightedCaptchaCode = computed(() => {
    if (!results.value?.captcha_encryption) return '';
    try { return hljs.highlight(results.value.captcha_encryption, { language: 'javascript' }).value; }
    catch { return hljs.highlightAuto(results.value.captcha_encryption).value; }
});

const runAnalysisOnce = async (quiet: boolean): Promise<boolean> => {
    error.value = null;
    results.value = null;
    edgeIpRace.value = null;
    logs.value = [];

    if (useProxy.value && !proxyUrl.value.trim()) {
        error.value = 'Proxy URL is required when proxy mode is enabled';
        return false;
    }

    localStorage.setItem('captcha_monitor_proxy_v3', proxyUrl.value);
    localStorage.setItem('captcha_monitor_use_proxy', String(useProxy.value));
    loading.value = true;
    analysisStartTime.value = Date.now();

    if (pollInterval) clearInterval(pollInterval);
    pollInterval = setInterval(pollProgress, 1000);

    let succeeded = false;
    try {
        const response = await axios.post('/api/captcha-algorithm/analyze', {
            proxy: useProxy.value ? proxyUrl.value : '',
        }, { timeout: 200000 });

        edgeIpRace.value = response.data.edge_ip_race ?? null;

        if (response.data.error) {
            error.value = response.data.error;
            logs.value = response.data.logs ?? [];
            if (!quiet) toast.error(error.value ?? 'Analysis failed');
        } else {
            succeeded = true;
            results.value = response.data;
            logs.value = response.data.logs ?? [];
            if (response.data.bundle_versions) applyVersionsPayload(response.data.bundle_versions);
            else loadVersions();
            if (response.data.engine) engine.value = response.data.engine;
            else loadEngine();

            if (response.data.extraction_alarm?.triggered) {
                toast.error('Extraction failed — bundle structure changed. Encryption may be broken; review the alarm before booking.');
            } else if (response.data.snapshot_status === 'changed') {
                toast.error('Algorithm constants changed! Review required.');
            } else if (response.data.snapshot_status === 'new' && !response.data.previous_snapshot) {
                toast.success('First snapshot recorded.');
            } else {
                toast.success('Analysis complete — no changes detected.');
            }

            if (response.data.providers_enabled > 0) {
                toast.success(`${response.data.providers_enabled} captcha provider(s) re-enabled.`);
            } else if (response.data.providers_withheld_reason) {
                // Providers stay disabled unless the extraction was clean — the bot must not be released
                // to race against a rotated deployment with last-known-good encryption.
                toast.error(`Captcha providers left disabled — ${response.data.providers_withheld_reason}`);
            }
        }
    } catch (e: any) {
        error.value = e?.response?.data?.error ?? e?.message ?? 'Analysis failed';
        logs.value = e?.response?.data?.logs ?? [];
        if (!quiet) toast.error(error.value ?? 'Analysis failed');
    } finally {
        loading.value = false;
        analysisStartTime.value = null;
        if (pollInterval) { clearInterval(pollInterval); pollInterval = null; }
    }
    return succeeded;
};

const runAnalysis = () => { void runAnalysisOnce(false); };

const RETRY_DELAY_MIN_MS = 100;
const RETRY_DELAY_MAX_MS = 1000;
const retryDelayMs = ref(500);
const autoRetrying = ref(false);
const retryAttempt = ref(0);
const retryCountdown = ref(0);

const clampRetryDelay = (): void => {
    let v = Math.round(Number(retryDelayMs.value));
    if (!Number.isFinite(v)) { v = RETRY_DELAY_MAX_MS; }
    retryDelayMs.value = Math.min(RETRY_DELAY_MAX_MS, Math.max(RETRY_DELAY_MIN_MS, v));
};

const sleep = (ms: number) => new Promise<void>((resolve) => setTimeout(resolve, ms));

const analyzeUntilSuccess = async () => {
    if (autoRetrying.value) {
        autoRetrying.value = false;
        retryCountdown.value = 0;
        return;
    }
    if (useProxy.value && !proxyUrl.value.trim()) {
        error.value = 'Proxy URL is required when proxy mode is enabled';
        return;
    }

    autoRetrying.value = true;
    retryAttempt.value = 0;

    while (autoRetrying.value) {
        retryAttempt.value++;
        const succeeded = await runAnalysisOnce(true);

        if (succeeded) {
            if (retryAttempt.value > 1) {
                toast.success(`Analysis succeeded on attempt ${retryAttempt.value}`);
            }
            break;
        }
        if (!autoRetrying.value) break;

        clampRetryDelay();
        const delay = retryDelayMs.value;
        const step = 50;
        retryCountdown.value = delay;
        for (let remaining = delay; remaining > 0 && autoRetrying.value; remaining -= step) {
            await sleep(Math.min(step, remaining));
            retryCountdown.value = Math.max(0, remaining - step);
        }
        retryCountdown.value = 0;
    }

    autoRetrying.value = false;
    retryCountdown.value = 0;
};

const copyLogs = async () => {
    try {
        await navigator.clipboard.writeText(logs.value.join('\n'));
        logsCopied.value = true;
        setTimeout(() => { logsCopied.value = false; }, 2000);
    } catch {
        toast.error('Failed to copy logs');
    }
};

const copyToClipboard = async (text: string | null, key: string) => {
    if (!text) return;
    try {
        await navigator.clipboard.writeText(text);
        copied.value = key;
        setTimeout(() => { if (copied.value === key) copied.value = null; }, 2000);
        toast.info('Copied to clipboard');
    } catch {
        toast.error('Failed to copy');
    }
};


const truncate = (s: string, n: number) => s.length > n ? s.slice(0, n) + '…' : s;

const formatDownloadStart = (iso: string): string => {
    const d = new Date(iso);
    if (isNaN(d.getTime())) return iso;
    return d.toLocaleString('en-GB', {
        timeZone: 'Asia/Dhaka',
        day: '2-digit', month: 'short',
        hour: '2-digit', minute: '2-digit', second: '2-digit',
        hour12: false,
    }) + ' BDT';
};

const formatDownloadDuration = (ms: number): string => {
    if (ms < 1000) return `${ms} ms`;
    return `${(ms / 1000).toFixed(2)} s`;
};

</script>
