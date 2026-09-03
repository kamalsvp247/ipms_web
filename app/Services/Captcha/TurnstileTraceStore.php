<?php

namespace App\Services\Captcha;

/**
 * Reader for the challenge traces app/Scripts/in_house_captcha_solver.cjs captures.
 *
 * A trace is one instrumented solve: every request Cloudflare's challenge sequence makes,
 * with its wire headers, bodies, initiator and ordering. It is the specification the
 * protocol-emulation tier is built against, so the files are treated as read-only evidence
 * here — only the sidecar writes them, and only while holding a browser.
 *
 * Bodies are large on purpose (a single capture carries ~270 KB of iframe bootstrap and
 * ~800 KB of challenge payload), so anything user-facing goes through summarise().
 */
class TurnstileTraceStore
{
    /** Enough of a body to recognise its shape without shipping the payload. */
    private const PREVIEW_CHARS = 600;

    /**
     * Trace filenames are generated from an ISO timestamp. Anything else is refused rather
     * than sanitised: these names only ever come from our own writer, so a mismatch means
     * the caller is doing something the store has no reason to support.
     */
    private const NAME_PATTERN = '/^[0-9A-Za-z:.\-]+\.json$/';

    public function directory(): string
    {
        return storage_path('app/captcha/turnstile_traces');
    }

    /**
     * Absolute path for a trace, or null when the name is not one we would have written.
     */
    public function path(string $file): ?string
    {
        if (! preg_match(self::NAME_PATTERN, $file) || str_contains($file, '..')) {
            return null;
        }

        return $this->directory().'/'.$file;
    }

    /**
     * Captured traces, newest first.
     *
     * @return list<array{file: string, bytes: int, captured_at: string|null, solved: bool, requests: int, challenge_requests: int}>
     */
    public function list(): array
    {
        $files = glob($this->directory().'/*.json') ?: [];
        rsort($files);

        $traces = [];

        foreach ($files as $path) {
            $trace = $this->decode($path);

            if ($trace === null) {
                continue;
            }

            $traces[] = [
                'file' => basename($path),
                'bytes' => (int) filesize($path),
                'captured_at' => $trace['captured_at'] ?? null,
                'solved' => (bool) ($trace['outcome']['solved'] ?? false),
                'requests' => (int) ($trace['summary']['requests'] ?? 0),
                'challenge_requests' => count($trace['summary']['challenge_sequence'] ?? []),
            ];
        }

        return $traces;
    }

    /**
     * The newest capture, or null when none exists. This is what the later emulation steps
     * derive from, so they always work against the most recent observation of the flow.
     *
     * @return array<string, mixed>|null
     */
    public function latest(): ?array
    {
        $files = glob($this->directory().'/*.json') ?: [];
        rsort($files);

        return $files === [] ? null : $this->decode($files[0]);
    }

    /**
     * One full trace, bodies included.
     *
     * @return array<string, mixed>|null
     */
    public function read(string $file): ?array
    {
        $path = $this->path($file);

        return $path === null ? null : $this->decode($path);
    }

    /**
     * Reduce a trace for display: bodies become a preview plus their true length, so the
     * sequence stays readable in a browser without shipping a megabyte of challenge script.
     *
     * @param  array<string, mixed>  $trace
     * @return array<string, mixed>
     */
    public function summarise(array $trace): array
    {
        $trace['calls'] = array_map(function (array $call): array {
            foreach (['request_body', 'response_body'] as $field) {
                $body = $call[$field] ?? null;

                $call[$field.'_length'] = is_string($body) ? strlen($body) : 0;
                $call[$field] = is_string($body) ? substr($body, 0, self::PREVIEW_CHARS) : null;
            }

            return $call;
        }, $trace['calls'] ?? []);

        return $trace;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decode(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }
}
