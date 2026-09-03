<?php

namespace App\Services\Captcha;

/**
 * Reader for the fingerprint-bisect reports app/Scripts/in_house_captcha_solver.cjs writes.
 *
 * A report is a measurement, not a configuration: it records which browser signals made the
 * live widget refuse to issue a token, and is what step 3 of the emulation plan hands to
 * step 4 as the minimal set an emulator has to reproduce. Reports are kept apart from the
 * traces because they share none of their shape.
 */
class TurnstileBisectStore
{
    public function directory(): string
    {
        return storage_path('app/captcha/turnstile_bisect');
    }

    /**
     * Every report, newest first.
     *
     * @return list<array{file: string, captured_at: string|null, samples_per_arm: int, baseline_rate: int, checked: list<string>}>
     */
    public function list(): array
    {
        $files = glob($this->directory().'/*.json') ?: [];
        rsort($files);

        $reports = [];

        foreach ($files as $path) {
            $report = $this->decode($path);

            if ($report === null) {
                continue;
            }

            $reports[] = [
                'file' => basename($path),
                'captured_at' => $report['captured_at'] ?? null,
                'samples_per_arm' => (int) ($report['samples_per_arm'] ?? 0),
                'baseline_rate' => (int) ($report['baseline']['rate'] ?? 0),
                'checked' => $report['checked'] ?? [],
            ];
        }

        return $reports;
    }

    /**
     * The most recent report in full, or null when none has been run.
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
