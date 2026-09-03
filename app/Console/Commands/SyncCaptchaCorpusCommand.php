<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Keep the never-pruned extractor regression corpus populated.
 *
 * CaptchaAlgorithmCorpusTest pins a set of real IVAC bundles by sha256 prefix. Those files
 * used to live in storage/app/captcha/bundles, which CaptchaBundleVersionService retention-
 * prunes — so every pinned fixture eventually aged off disk and the suite silently degraded
 * to "green with 6 skips". Pinned fixtures now live in storage/app/captcha/corpus, which
 * nothing prunes; this command restores any that are missing from the still-archived set and
 * names the ones that are gone for good.
 */
class SyncCaptchaCorpusCommand extends Command
{
    protected $signature = 'captcha-corpus:sync
                            {--adopt= : sha256 (or prefix) of an archived bundle to copy into the corpus}';

    protected $description = 'Restore pinned extractor-corpus bundles into the never-pruned corpus directory';

    public function handle(): int
    {
        $corpusDir = storage_path('app/captcha/corpus');
        $archiveDir = storage_path('app/captcha/bundles');

        if (! is_dir($corpusDir) && ! @mkdir($corpusDir, 0775, true) && ! is_dir($corpusDir)) {
            $this->error("Could not create {$corpusDir}");

            return self::FAILURE;
        }

        if ($adopt = $this->option('adopt')) {
            return $this->adopt((string) $adopt, $archiveDir, $corpusDir);
        }

        $restored = 0;
        $missing = [];

        foreach ($this->pinnedPrefixes() as $prefix) {
            if (! empty(glob($corpusDir.'/'.$prefix.'*.js'))) {
                continue;
            }

            $archived = glob($archiveDir.'/'.$prefix.'*.js');
            if (empty($archived)) {
                $missing[] = $prefix;

                continue;
            }

            copy($archived[0], $corpusDir.'/'.basename($archived[0]));
            $restored++;
            $this->info("Restored {$prefix} from the archive.");
        }

        $this->info('Corpus holds '.count(glob($corpusDir.'/*.js'))." bundle(s); restored {$restored}.");

        if ($missing !== []) {
            $this->warn('Unrecoverable (pruned before they were pinned): '.implode(', ', $missing));
            $this->warn('Remove those entries from CaptchaAlgorithmCorpusTest::CORPUS — a fixture that can never exist keeps the corpus permanently incomplete.');
        }

        return self::SUCCESS;
    }

    /**
     * Copy one archived bundle into the corpus so it can be pinned in the test.
     */
    private function adopt(string $hash, string $archiveDir, string $corpusDir): int
    {
        $matches = glob($archiveDir.'/'.$hash.'*.js') ?: glob($corpusDir.'/'.$hash.'*.js');

        if (empty($matches)) {
            $this->error("No archived bundle matches {$hash}.");

            return self::FAILURE;
        }

        $target = $corpusDir.'/'.basename($matches[0]);
        if (! is_file($target)) {
            copy($matches[0], $target);
        }

        $this->info('Adopted '.basename($target).' — pin it in CaptchaAlgorithmCorpusTest::CORPUS.');

        return self::SUCCESS;
    }

    /**
     * The sha256 prefixes pinned by the corpus test, read from the test file itself so the
     * pinned set has exactly one source of truth.
     *
     * @return array<int, string>
     */
    private function pinnedPrefixes(): array
    {
        $test = base_path('tests/Feature/Captcha/CaptchaAlgorithmCorpusTest.php');
        if (! is_file($test)) {
            return [];
        }

        preg_match_all("/^\s*'([0-9a-f]{8})' => \[/m", (string) file_get_contents($test), $m);

        return array_values(array_unique($m[1] ?? []));
    }
}
