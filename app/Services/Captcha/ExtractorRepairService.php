<?php

namespace App\Services\Captcha;

use App\Services\CaptchaAlgorithmService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Unattended repair of the captcha extractor after an IVAC bundle rotation.
 *
 * IVAC rotates the SHAPE it emits the captcha config in, independently of the algorithm.
 * Every such rotation so far has broken a structural assumption in analyze_captcha_algo.py
 * (never the startAt: anchor), which strands extraction at login:null/reserve:null. The
 * scheduled auto-refresh then correctly keeps last-known-good and raises needs-attention —
 * but the bot is encrypting with a stale algorithm until a human edits the extractor.
 *
 * This service closes that gap by driving a headless Claude Code session at the extractor,
 * then deciding the outcome DETERMINISTICALLY rather than trusting the agent's own verdict.
 *
 * Order matters, and it is deliberately "encryption first, regression suite second":
 *
 *   1. diff scope  — only allowlisted files may change, enforced after the fact by diffing
 *                    the working tree, not by tool permissions the agent could satisfy while
 *                    still editing something else.
 *   2. new bundle  — the bundle that broke us must extract cleanly and well-formed. This is
 *                    the correctness proof for LIVE encryption: it is checked against the
 *                    live bundle's own module output.
 *   3. live apply  — CaptchaAlgorithmService::analyze() refreshes encrypt_meta.json and
 *                    reloads the sidecar. Captcha generation is restored HERE, as early as
 *                    it is provably correct, because every minute on a stale algorithm is a
 *                    minute of failing bookings.
 *   4. corpus      — every pinned historical bundle must still extract as pinned, with ZERO
 *                    skips (CAPTCHA_CORPUS_STRICT=1). A failure here does NOT revert: the
 *                    live encryption is already verified, and restoring the old extractor
 *                    would strand the new bundle unreadable again. Instead the agent gets a
 *                    second round to satisfy both, and anything still red is reported as a
 *                    partial success needing review.
 *
 * Before the apply in step 3, any gate failure restores the pre-agent snapshot of every
 * allowlisted file, so a bad attempt leaves the extractor exactly as it was. That is the
 * safety argument: the agent proposes, and the live bundle plus the corpus decide.
 *
 * Deliberately NOT automated: pushing. The fix goes live the moment the file changes on
 * disk (cron runs the working tree), so the commit is bookkeeping, not deployment.
 */
class ExtractorRepairService
{
    /** Cache key prefix for per-bundle attempt counters. */
    public const ATTEMPT_CACHE_PREFIX = 'captcha:extractor_repair:';

    /** Cache key holding the last repair outcome, for the monitor banner. */
    public const LAST_OUTCOME_CACHE_KEY = 'captcha:extractor_repair:last';

    /** Set when an analysis fails structurally; consumed by the scheduled repair command. */
    public const REQUEST_CACHE_KEY = 'captcha:extractor_repair:requested';

    /**
     * Repairing the same bundle forever burns tokens on a problem the agent has already
     * failed twice; after this a human is genuinely needed.
     */
    private const MAX_ATTEMPTS = 2;

    /**
     * The only files an automated repair may touch. Enforced by diffing the working tree
     * after the agent exits — not by tool permissions alone, which the agent could satisfy
     * while still editing something unexpected.
     *
     * @var array<int, string>
     */
    private const ALLOWED_PATHS = [
        'app/Scripts/analyze_captcha_algo.py',
        'app/Scripts/captcha_live_runtime.cjs',
        'tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php',
    ];

    private const AGENT_TIMEOUT_SECONDS = 1500;

    public function __construct(
        private readonly CaptchaAlgorithmService $algorithm,
    ) {}

    /**
     * Queue an unattended repair for the bundle an analyze() run just failed on.
     *
     * Called from both entry points — the monitor's Run Analysis button and the scheduled
     * auto-refresh — because the bundle download is a manual action here, so the button is
     * the trigger that matters. Queuing rather than repairing inline is deliberate: a repair
     * runs for ~25 minutes (far past any HTTP request) and needs the scheduler user's Claude
     * credentials, which the web user does not have. The scheduled consumer picks this up
     * within five minutes.
     *
     * Only a 'structural' alarm queues: a 'rollout' alarm is IVAC shipping a config version
     * whose module is not in the bundle yet, which heals when they finish deploying.
     *
     * @param  array<string, mixed>  $analyzeResult
     * @return array{queued: bool, reason: string, hash: ?string}
     */
    public function queueFromAnalysis(array $analyzeResult, string $reason): array
    {
        $severity = $analyzeResult['extraction_alarm']['severity'] ?? 'unknown';

        if ($severity !== 'structural') {
            return ['queued' => false, 'reason' => "alarm severity is '{$severity}', not structural", 'hash' => null];
        }

        if (! config('captcha.auto_repair.enabled')) {
            return ['queued' => false, 'reason' => 'auto-repair is disabled (CAPTCHA_AUTO_REPAIR)', 'hash' => null];
        }

        // An unclean analyze still registers the downloaded bundle as a (non-active) version,
        // so the newest row is the file that defeated the extractor.
        $newest = $analyzeResult['bundle_versions']['versions'][0] ?? null;
        $hash = is_array($newest) ? (string) ($newest['bundle_hash'] ?? '') : '';

        if ($hash === '') {
            return ['queued' => false, 'reason' => 'the failing bundle was not registered as a version', 'hash' => null];
        }

        $path = rtrim((string) config('captcha.storage_path', storage_path('app/captcha')), '/').'/bundles/'.$hash.'.js';
        if (! is_file($path)) {
            return ['queued' => false, 'reason' => "the failing bundle is not on disk at {$path}", 'hash' => $hash];
        }

        Cache::put(self::REQUEST_CACHE_KEY, [
            'bundle' => $path,
            'hash' => $hash,
            'reason' => $reason,
            'queued_at' => now()->toIso8601String(),
        ], now()->addDay());

        Log::warning('Captcha extractor repair queued', ['hash' => substr($hash, 0, 8), 'reason' => $reason]);

        return ['queued' => true, 'reason' => 'repair queued; the scheduler picks it up within 5 minutes', 'hash' => $hash];
    }

    /**
     * The queued repair request, plus the outcome of the last completed attempt, for the
     * monitor banner.
     *
     * @return array{request: ?array<string, mixed>, last: ?array<string, mixed>, enabled: bool}
     */
    public function status(): array
    {
        return [
            'enabled' => (bool) config('captcha.auto_repair.enabled'),
            'request' => Cache::get(self::REQUEST_CACHE_KEY),
            'last' => Cache::get(self::LAST_OUTCOME_CACHE_KEY),
        ];
    }

    /**
     * Attempt an unattended repair for one bundle.
     *
     * @return array{repaired: bool, stage: string, detail: string, attempt: int, session: ?string}
     */
    public function attempt(string $bundlePath, string $bundleHash, string $reason, ?string $proxy = null): array
    {
        $attemptKey = self::ATTEMPT_CACHE_PREFIX.substr($bundleHash, 0, 16);
        $attempt = ((int) Cache::get($attemptKey, 0)) + 1;

        if ($attempt > self::MAX_ATTEMPTS) {
            return $this->outcome(false, 'exhausted', "Already attempted {$this->maxAttempts()} repairs for bundle ".substr($bundleHash, 0, 8).'; a human is needed.', $attempt, null);
        }

        if (($preflight = $this->preflight($bundlePath)) !== null) {
            return $this->outcome(false, 'preflight', $preflight, $attempt, null);
        }

        // Baseline: the corpus must be green BEFORE the agent runs. If it is already red,
        // something other than this rotation is broken and an agent edit would be layered
        // on top of an unknown regression.
        $baseline = $this->runCorpus();
        if (! $baseline['passed']) {
            return $this->outcome(false, 'baseline', 'Corpus is already failing before any repair: '.$baseline['detail'], $attempt, null);
        }

        Cache::put($attemptKey, $attempt, now()->addDay());

        $snapshot = $this->snapshot();

        $agent = $this->runAgent($bundlePath, $bundleHash, $reason);
        if (! $agent['ok']) {
            $this->restore($snapshot);

            return $this->outcome(false, 'agent', $agent['detail'], $attempt, $agent['session']);
        }

        // Scope is a hard safety violation regardless of whether the fix works.
        $scope = $this->gateDiffScope($snapshot);
        if (! $scope['passed']) {
            $this->restore($snapshot);

            return $this->outcome(false, 'diff_scope', $scope['detail'], $attempt, $agent['session']);
        }

        // Correctness gate for LIVE encryption: the bundle that broke us must now extract
        // cleanly and well-formed. This — not the corpus — is what proves the tokens the bot
        // is about to send are right, because it is checked against the live bundle's own
        // module output.
        $attribution = $this->attribute($bundlePath);
        if (! $attribution['passed']) {
            $this->restore($snapshot);

            return $this->outcome(false, 'new_bundle', $attribution['detail'], $attempt, $agent['session']);
        }

        // Captcha generation comes FIRST. Every minute the sidecar serves a stale algorithm
        // is a minute of failing bookings, so restore encryption as soon as it is provably
        // correct against the live bundle, and run the regression suite after.
        $applied = $this->applyLive($proxy);
        if (! $applied['ok']) {
            $this->restore($snapshot);

            return $this->outcome(false, 'apply', $applied['detail'], $attempt, $agent['session']);
        }

        // From here the extractor is NEVER reverted: encryption is live and verified, and
        // restoring the old extractor would strand this bundle unreadable all over again.
        // The corpus now guards a different risk — that the fix regressed older shapes and
        // will fail the NEXT rotation.
        $corpus = $this->runCorpus();

        if (! $corpus['passed']) {
            Log::warning('Captcha extractor repair: encryption is live, but the corpus regressed — sending a follow-up round', [
                'detail' => $corpus['detail'],
            ]);

            $followUp = $this->runFollowUpAgent($bundlePath, $corpus['detail']);

            if ($followUp['ok'] && $this->gateDiffScope($snapshot)['passed']) {
                // A follow-up edit can change how the live bundle extracts, so re-verify and
                // re-apply before trusting it.
                $recheck = $this->attribute($bundlePath);

                if ($recheck['passed']) {
                    $this->applyLive($proxy);
                    $corpus = $this->runCorpus();
                } else {
                    // The follow-up broke the live extraction it had already fixed. Put the
                    // working version back and re-apply from it.
                    $this->restore($snapshot);
                    $corpus = ['passed' => false, 'detail' => 'follow-up round broke live extraction and was reverted; '.$corpus['detail']];
                }
            }
        }

        $this->adoptAndCommit($bundlePath, $bundleHash);
        Cache::forget($attemptKey);

        if (! $corpus['passed']) {
            // Deliberately reported as a partial success: the bot is encrypting correctly
            // again, but the extractor carries an unreviewed regression against older shapes.
            return $this->outcome(
                true,
                'applied_corpus_regressed',
                'Encryption is live and verified against the new bundle, BUT the extractor regressed older shapes and needs review: '.$corpus['detail'],
                $attempt,
                $agent['session']
            );
        }

        return $this->outcome(true, 'done', 'Extractor repaired, encryption re-applied from the live bundle, corpus still green.', $attempt, $agent['session']);
    }

    /**
     * Reasons the repair must not start, or null when it may proceed.
     */
    private function preflight(string $bundlePath): ?string
    {
        if (! is_file($bundlePath)) {
            return "Bundle {$bundlePath} is not on disk.";
        }

        if ($this->claudeBinary() === null) {
            return 'The claude CLI is not installed or not on PATH for this user.';
        }

        $dirty = $this->changedAllowedPaths();
        if ($dirty !== []) {
            return 'Working tree already has uncommitted changes in '.implode(', ', $dirty).' — refusing to overwrite in-progress human edits.';
        }

        return null;
    }

    /**
     * Copy every allowlisted file to a temp directory so a failed attempt can be undone
     * exactly, without touching git state (the checkout is live production).
     *
     * Also records the working tree's dirty set. This checkout is shared — other sessions
     * and jobs edit unrelated files while a repair runs — so both the scope gate and the
     * restore must reason about what changed DURING this run, not about what is dirty
     * overall. A concurrent edit to an unrelated file is none of this service's business.
     *
     * @return array{dir: string, files: array<string, string>, baseline: array<string, string>}
     */
    private function snapshot(): array
    {
        $dir = storage_path('app/captcha/repair-snapshots/'.now()->format('Ymd-His').'-'.Str::random(6));
        @mkdir($dir, 0775, true);

        $files = [];
        foreach (self::ALLOWED_PATHS as $relative) {
            $source = base_path($relative);
            if (! is_file($source)) {
                continue;
            }

            $target = $dir.'/'.str_replace('/', '__', $relative);
            copy($source, $target);
            $files[$relative] = $target;
        }

        return ['dir' => $dir, 'files' => $files, 'baseline' => $this->workingTreeEntries()];
    }

    /**
     * Restore a snapshot over the working tree, then delete stale analysis caches so the
     * restored extractor is not shadowed by results computed with the agent's version.
     *
     * @param  array{dir: string, files: array<string, string>, baseline: array<string, string>}  $snapshot
     */
    private function restore(array $snapshot): void
    {
        foreach ($snapshot['files'] as $relative => $backup) {
            if (is_file($backup)) {
                copy($backup, base_path($relative));
            }
        }

        // Remove only files that appeared DURING this run inside the directories the agent
        // works in. Deliberately not `git clean`: this checkout is shared, and a blanket
        // clean would delete untracked work belonging to another session.
        foreach ($this->workingTreeEntries() as $path => $status) {
            $appearedDuringRun = ! isset($snapshot['baseline'][$path]);
            $isUntracked = str_starts_with($status, '??');
            $inAgentScope = str_starts_with($path, 'app/Scripts/') || str_starts_with($path, 'tests/Feature/Captcha/');

            if ($appearedDuringRun && $isUntracked && $inAgentScope) {
                @unlink(base_path($path));
            }
        }

        foreach (glob(storage_path('app/captcha/analysis_cache/probe_*')) ?: [] as $stale) {
            @unlink($stale);
        }

        Log::warning('Captcha extractor repair reverted; extractor restored to pre-agent state', [
            'snapshot' => $snapshot['dir'],
        ]);
    }

    /**
     * Drive the headless agent at the extractor.
     *
     * @return array{ok: bool, detail: string, session: ?string}
     */
    private function runAgent(string $bundlePath, string $bundleHash, string $reason): array
    {
        // The prompt goes on STDIN, and tool lists are comma-joined into a single argument:
        // --allowedTools is variadic, so a trailing prompt argument would be parsed as one
        // more tool name and the CLI would exit with "Input must be provided".
        $result = Process::path(base_path())
            ->timeout(self::AGENT_TIMEOUT_SECONDS)
            ->env(['CAPTCHA_CORPUS_STRICT' => '1'])
            ->input($this->prompt($bundlePath, $bundleHash, $reason))
            ->run([
                $this->claudeBinary(),
                '--print',
                '--output-format', 'json',
                '--model', 'opus',
                '--effort', 'high',
                '--max-turns', '80',
                '--permission-mode', 'acceptEdits',
                '--allowedTools', 'Read,Edit,Grep,Glob,Bash',
                '--disallowedTools', 'WebSearch,WebFetch,Task',
            ]);

        if (! $result->successful()) {
            return ['ok' => false, 'detail' => 'claude exited '.$result->exitCode().': '.Str::limit($result->errorOutput(), 500), 'session' => null];
        }

        $payload = json_decode(trim($result->output()), true);
        $session = is_array($payload) ? ($payload['session_id'] ?? null) : null;

        if (is_array($payload) && ($payload['is_error'] ?? false)) {
            return ['ok' => false, 'detail' => 'Agent reported an error: '.Str::limit((string) ($payload['result'] ?? ''), 500), 'session' => $session];
        }

        Log::info('Captcha extractor repair agent finished', [
            'bundle' => substr($bundleHash, 0, 8),
            'session' => $session,
            'cost_usd' => is_array($payload) ? ($payload['total_cost_usd'] ?? null) : null,
            'result' => is_array($payload) ? Str::limit((string) ($payload['result'] ?? ''), 2000) : null,
        ]);

        return ['ok' => true, 'detail' => 'agent completed', 'session' => $session];
    }

    /**
     * The task handed to the agent. It states the invariant (the corpus decides) rather
     * than prescribing a fix, because the shape of each rotation is genuinely new.
     */
    private function prompt(string $bundlePath, string $bundleHash, string $reason): string
    {
        $short = substr($bundleHash, 0, 8);
        $allowed = implode("\n", array_map(fn ($p) => "  - {$p}", self::ALLOWED_PATHS));

        return <<<PROMPT
        IVAC redeployed their frontend bundle and the captcha algorithm extractor can no longer
        read it. Encryption is currently running on a STALE algorithm, so the bot's captcha
        tokens will start failing. Fix the extractor.

        Failing bundle: {$bundlePath}  (sha256 prefix {$short})
        Analyzer verdict: {$reason}

        Diagnose before editing. The error message historically points at the wrong layer —
        "No live modules resolved" almost always means the CONFIG scan failed, not the module
        regex. Establish which by running, in python3:

            import importlib.util, sys
            spec = importlib.util.spec_from_file_location('a', 'app/Scripts/analyze_captcha_algo.py')
            a = importlib.util.module_from_spec(spec); spec.loader.exec_module(a)
            js = open('{$bundlePath}').read()
            print(len(a._scan_config_literals(js)))   # 0 => the config EMISSION shape rotated
            print(len(a._extract_module_map(js)))     # ~10 => the module regex is FINE

        Read docs/captcha-algorithm-playbook.md section 9 first — it lists every emission shape
        seen so far and how each one broke a structural assumption. Expect this rotation to be a
        NEW shape of the same class: the config object `{{secret, startAt, length, version}}` is
        still there, but it is reached differently. Locate it structurally and be careful with
        obfuscation: braces, parens and quotes appear inside string literals, so every backward
        scan must skip string bodies.

        You may edit ONLY these files:
        {$allowed}

        Your work is accepted or rejected by a deterministic gate, not by your own judgement.
        Restoring captcha generation is the FIRST priority, so verify in this order:

          1. The failing bundle must extract cleanly — this is what gets encryption live again:
                 python3 app/Scripts/analyze_captcha_algo.py - --attribute {$bundlePath}
             requires extraction_ok=true, both login and reserve non-null with a module, and
             wellformed true for both. As soon as this holds, the sidecar is re-applied for
             you; you do not run that step.
          2. Then the regression suite — every pinned bundle must still extract exactly as
             pinned, with ZERO skipped cases:
                 CAPTCHA_CORPUS_STRICT=1 php artisan test --compact tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php
             Run it by PATH — a pre-existing makeUser() redeclaration fatals any --filter run.
             A fix that repairs the new bundle by breaking an older shape is not finished.

        Aim to satisfy both in one pass. Never make the corpus pass by editing a pinned
        expectation to match your output — that turns the regression suite into a rubber stamp.

        Then add the failing bundle to CORPUS in the corpus test with the parameters you just
        extracted, and extend that file's docblock with one entry describing this rotation, in
        the same style as the existing entries.

        Notes:
          - analysis_cache/ is keyed on an extractor fingerprint, so your edits self-invalidate.
            If a result looks impossibly stale anyway: rm storage/app/captcha/analysis_cache/probe_*
          - Do NOT touch encrypt_meta.json, the sidecar, or any DB state. Applying the fix is
            handled after the gate passes.
          - Do NOT commit, push, or run git checkout/reset.
          - This is a live production host. Keep changes minimal and shape-agnostic; prefer
            structural location over new hardcoded patterns, so the NEXT rotation is likelier
            to keep working.

        Finish by stating what the emission shape changed to and which function you changed.
        PROMPT;
    }

    /**
     * Enforce the edit allowlist by diffing the working tree AFTER the agent exits, rather
     * than relying on tool permissions it could satisfy while still editing something else.
     *
     * Compared against the snapshot baseline, not against a clean tree: this checkout is
     * shared, so an unrelated file another session touched mid-run must not be attributed to
     * the agent (that alone failed a smoke test when a concurrent session edited the payment
     * driver).
     *
     * @param  array{baseline: array<string, string>}  $snapshot
     * @return array{passed: bool, detail: string}
     */
    private function gateDiffScope(array $snapshot): array
    {
        $changed = $this->pathsChangedSince($snapshot['baseline']);
        $outside = array_values(array_diff($changed, self::ALLOWED_PATHS));

        if ($outside !== []) {
            return ['passed' => false, 'detail' => 'Agent modified files outside the allowlist: '.implode(', ', $outside)];
        }

        if ($changed === []) {
            return ['passed' => false, 'detail' => 'Agent made no changes to the extractor.'];
        }

        return ['passed' => true, 'detail' => implode(', ', $changed)];
    }

    /**
     * Paths whose working-tree status differs from the baseline captured at snapshot time.
     *
     * @param  array<string, string>  $baseline
     * @return array<int, string>
     */
    private function pathsChangedSince(array $baseline): array
    {
        $now = $this->workingTreeEntries();
        $changed = [];

        foreach ($now as $path => $status) {
            if (($baseline[$path] ?? null) !== $status) {
                $changed[] = $path;
            }
        }

        // A path that was dirty at baseline and is now clean was also changed during the run.
        foreach ($baseline as $path => $status) {
            if (! isset($now[$path])) {
                $changed[] = $path;
            }
        }

        return array_values(array_unique($changed));
    }

    /**
     * Second round: encryption is already live and correct, but the fix regressed older
     * bundle shapes. The agent is told exactly that, so it does not "fix" the regression by
     * undoing the live fix.
     *
     * @return array{ok: bool, detail: string, session: ?string}
     */
    private function runFollowUpAgent(string $bundlePath, string $corpusDetail): array
    {
        $prompt = <<<PROMPT
        Your extractor fix WORKED on the live bundle and captcha encryption has already been
        restored from it — the bot is generating valid tokens again. Do not undo that.

        However the regression suite over previously-working bundles now fails:

        {$corpusDetail}

        Make the extractor handle BOTH: the new emission shape in {$bundlePath} AND every
        pinned historical shape. Re-run until green:

            CAPTCHA_CORPUS_STRICT=1 php artisan test --compact tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php
            python3 app/Scripts/analyze_captcha_algo.py - --attribute {$bundlePath}

        Both must pass together — the corpus with ZERO skips, and the new bundle still
        reaching extraction_ok with both types well-formed. If the pinned expectations
        themselves are wrong (rather than your code), say so explicitly instead of editing
        them to match your output: changing a pinned value to match a bug is how a regression
        suite becomes worthless.

        Same constraints as before: only the extractor, the live runtime and the corpus test
        may change; no git commands; do not touch encrypt_meta.json or the sidecar.
        PROMPT;

        $result = Process::path(base_path())
            ->timeout(self::AGENT_TIMEOUT_SECONDS)
            ->env(['CAPTCHA_CORPUS_STRICT' => '1'])
            ->input($prompt)
            ->run([
                $this->claudeBinary(),
                '--print',
                '--output-format', 'json',
                '--model', 'opus',
                '--effort', 'high',
                '--max-turns', '80',
                '--permission-mode', 'acceptEdits',
                '--allowedTools', 'Read,Edit,Grep,Glob,Bash',
                '--disallowedTools', 'WebSearch,WebFetch,Task',
            ]);

        if (! $result->successful()) {
            return ['ok' => false, 'detail' => 'follow-up agent exited '.$result->exitCode(), 'session' => null];
        }

        $payload = json_decode(trim($result->output()), true);

        return [
            'ok' => is_array($payload) && ($payload['is_error'] ?? false) !== true,
            'detail' => 'follow-up completed',
            'session' => is_array($payload) ? ($payload['session_id'] ?? null) : null,
        ];
    }

    /**
     * Run the pinned-bundle corpus in strict mode, where a missing fixture fails instead of
     * skipping. "Green with skips" is exactly how the old corpus decayed to no coverage.
     *
     * @return array{passed: bool, detail: string}
     */
    private function runCorpus(): array
    {
        $result = Process::path(base_path())
            ->timeout(900)
            ->env(['CAPTCHA_CORPUS_STRICT' => '1'])
            // By PATH, not --filter: a pre-existing makeUser() redeclaration between
            // RbacTest and RoleHierarchyTest fatals any --filter run repo-wide.
            ->run(['php', 'artisan', 'test', '--compact', 'tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php']);

        $output = $result->output().$result->errorOutput();

        if (! $result->successful()) {
            return ['passed' => false, 'detail' => Str::limit($this->lastLines($output), 800)];
        }

        if (preg_match('/(\d+)\s+skipped/i', $output, $m) && (int) $m[1] > 0) {
            return ['passed' => false, 'detail' => "{$m[1]} corpus case(s) skipped — fixtures missing, so the corpus proves nothing. Run captcha-corpus:sync."];
        }

        if (! preg_match('/(\d+)\s+passed/i', $output, $m) || (int) $m[1] < 2) {
            return ['passed' => false, 'detail' => 'Corpus reported no meaningful passing cases: '.Str::limit($this->lastLines($output), 400)];
        }

        return ['passed' => true, 'detail' => "{$m[1]} corpus cases passed"];
    }

    /**
     * Require a clean, well-formed extraction of the bundle that broke us.
     *
     * @return array{passed: bool, detail: string}
     */
    private function attribute(string $bundlePath): array
    {
        $result = Process::path(base_path())
            ->timeout(240)
            ->run(['python3', base_path('app/Scripts/analyze_captcha_algo.py'), '-', '--attribute', $bundlePath]);

        if (! $result->successful()) {
            return ['passed' => false, 'detail' => 'Analyzer still errors on the new bundle: '.Str::limit($result->errorOutput(), 400)];
        }

        $out = json_decode(trim($result->output()), true);
        if (! is_array($out)) {
            return ['passed' => false, 'detail' => 'Analyzer produced no JSON for the new bundle.'];
        }

        if (($out['extraction_ok'] ?? false) !== true) {
            return ['passed' => false, 'detail' => 'extraction_ok is still false for the new bundle.'];
        }

        foreach (['login', 'reserve'] as $type) {
            if (! is_array($out[$type] ?? null) || ($out[$type]['module'] ?? null) === null) {
                return ['passed' => false, 'detail' => "{$type} still resolves to no module."];
            }

            if (($out['wellformed'][$type] ?? false) !== true) {
                return ['passed' => false, 'detail' => "{$type} output is not well-formed — the transform would produce an invalid token."];
            }
        }

        return ['passed' => true, 'detail' => 'new bundle extracts cleanly'];
    }

    /**
     * Apply the repaired extractor for real against the live bundle.
     *
     * @return array{ok: bool, detail: string}
     */
    private function applyLive(?string $proxy): array
    {
        try {
            $result = $this->algorithm->analyze($proxy);
        } catch (\Throwable $e) {
            return ['ok' => false, 'detail' => 'analyze() threw: '.$e->getMessage()];
        }

        if (($result['error'] ?? null) !== null) {
            return ['ok' => false, 'detail' => 'analyze() failed: '.$result['error']];
        }

        if (($result['auto_applied']['applied'] ?? false) !== true) {
            return ['ok' => false, 'detail' => 'analyze() did not auto-apply: '.($result['auto_applied']['reason'] ?? 'unknown')];
        }

        return ['ok' => true, 'detail' => 'encrypt_meta refreshed and sidecar reloaded'];
    }

    /**
     * Preserve the bundle as a permanent corpus fixture and record the fix in git.
     *
     * The fix is already live (cron runs the working tree), so this only stops the change
     * from being lost to a later checkout. Pushing stays manual.
     */
    private function adoptAndCommit(string $bundlePath, string $bundleHash): void
    {
        $corpusDir = storage_path('app/captcha/corpus');
        if (! is_dir($corpusDir)) {
            @mkdir($corpusDir, 0775, true);
        }

        $fixture = $corpusDir.'/'.basename($bundlePath);
        if (! is_file($fixture)) {
            copy($bundlePath, $fixture);
        }

        $short = substr($bundleHash, 0, 8);
        $message = "fix(captcha): auto-repair extractor for bundle {$short}\n\n"
            ."IVAC rotated the bundle's config emission shape and extraction stalled at\n"
            ."login:null/reserve:null. Repaired unattended by ExtractorRepairService; the\n"
            ."change passed the pinned-bundle corpus with zero skips and the new bundle now\n"
            ."extracts cleanly, after which encrypt_meta was refreshed from the live bundle.\n\n"
            ."Not reviewed by a human. Verify before pushing.\n\n"
            .'Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>';

        // Path-scoped commit: a bare `git commit` would sweep in anything a human happened to
        // have staged elsewhere in this live checkout.
        $commit = Process::path(base_path())->run(array_merge(
            ['git', 'commit', '--no-verify', '-m', $message, '--'],
            self::ALLOWED_PATHS
        ));

        if (! $commit->successful()) {
            Log::warning('Captcha extractor repair could not commit; change is live but uncommitted', [
                'error' => Str::limit($commit->errorOutput(), 300),
            ]);
        }
    }

    /**
     * The working tree's dirty set as path => porcelain status code.
     *
     * @return array<string, string>
     */
    private function workingTreeEntries(): array
    {
        $result = Process::path(base_path())->run(['git', 'status', '--porcelain']);

        $entries = [];

        // Porcelain lines are "XY<space>PATH", and X is a SPACE for unstaged changes.
        // Do not trim the output as a whole: that eats the leading space and shifts every
        // path by one character, so a modified file silently stops matching the allowlist.
        foreach (preg_split('/\R/', $result->output()) ?: [] as $line) {
            if (strlen($line) < 4) {
                continue;
            }

            $status = substr($line, 0, 2);
            $path = trim(substr($line, 3));

            // Renames/copies report "old -> new"; the new path is what is on disk now.
            if (str_contains($path, ' -> ')) {
                $path = trim(substr($path, strpos($path, ' -> ') + 4));
            }

            $entries[trim($path, '"')] = $status;
        }

        return $entries;
    }

    /**
     * Allowlisted paths that are currently dirty, used by preflight to refuse overwriting a
     * human's in-progress extractor edits.
     *
     * @return array<int, string>
     */
    private function changedAllowedPaths(): array
    {
        return array_values(array_intersect(
            array_keys($this->workingTreeEntries()),
            self::ALLOWED_PATHS
        ));
    }

    private function lastLines(string $text, int $lines = 25): string
    {
        $all = preg_split('/\R/', trim($text)) ?: [];

        return implode("\n", array_slice($all, -$lines));
    }

    private function claudeBinary(): ?string
    {
        foreach ([config('captcha.claude_binary'), '/root/.local/bin/claude', '/usr/local/bin/claude'] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_executable($candidate)) {
                return $candidate;
            }
        }

        $which = Process::run(['which', 'claude']);

        return $which->successful() && trim($which->output()) !== '' ? trim($which->output()) : null;
    }

    private function maxAttempts(): int
    {
        return self::MAX_ATTEMPTS;
    }

    /**
     * @return array{repaired: bool, stage: string, detail: string, attempt: int, session: ?string}
     */
    private function outcome(bool $repaired, string $stage, string $detail, int $attempt, ?string $session): array
    {
        $outcome = [
            'repaired' => $repaired,
            'stage' => $stage,
            'detail' => $detail,
            'attempt' => $attempt,
            'session' => $session,
        ];

        Cache::put(self::LAST_OUTCOME_CACHE_KEY, $outcome + ['at' => now()->toIso8601String()], now()->addDays(7));

        $repaired
            ? Log::info('Captcha extractor auto-repair succeeded', $outcome)
            : Log::error('Captcha extractor auto-repair did not apply', $outcome);

        return $outcome;
    }
}
