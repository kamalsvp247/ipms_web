<?php

namespace App\Services;

use App\Support\IvacCallSummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AccountLiveStateRecorder
{
    /**
     * Columns the upsert refreshes, in the order they are bound. `logged_at` is written last on
     * purpose — see record().
     *
     * @var list<string>
     */
    private const COLUMNS = [
        'phone',
        'agent_slot_id',
        'phase',
        'method',
        'url',
        'status_code',
        'duration_ms',
        'message',
        'error_type',
        'logged_at',
    ];

    /**
     * Fold one ingest batch down to the newest API call per phone and upsert it.
     *
     * Console entries are skipped: `method=LOG` lines carry no status code, and the whole point
     * of the column is the response. Only the newest entry per phone in the batch is written, so
     * a 250-entry batch spanning ninety accounts still costs exactly one statement.
     *
     * Never allowed to throw. The bot blocks on this HTTP response while shipping logs, and a
     * cosmetic column is not worth failing an ingest that has already stored its rows.
     *
     * @param  array<int, array<string, mixed>>  $logs
     * @param  int|null  $slotId  Agent slot the batch came from
     */
    public function record(array $logs, ?int $slotId): int
    {
        try {
            $rows = $this->newestPerPhone($logs, $slotId);

            if (empty($rows)) {
                return 0;
            }

            DB::statement($this->upsertSql(count($rows)), $this->bindings($rows));

            return count($rows);
        } catch (\Throwable $e) {
            Log::warning('AccountLiveStateRecorder: failed to record live state', [
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $logs
     * @return array<string, array<string, mixed>>
     */
    private function newestPerPhone(array $logs, ?int $slotId): array
    {
        $newest = [];

        // First pass keeps raw entries only. Summarising here instead would json_decode every
        // response body in the batch to then throw most of them away — a 250-entry batch from
        // ninety accounts carries roughly two thirds superseded rows.
        foreach ($logs as $entry) {
            if (($entry['method'] ?? '') === 'LOG') {
                continue;
            }

            $phone = $entry['account_phone'] ?? null;

            if (! $phone || empty($entry['url'])) {
                continue;
            }

            $entry['logged_at'] ??= now()->format('Y-m-d H:i:s.v');

            if (isset($newest[$phone]) && $entry['logged_at'] < $newest[$phone]['logged_at']) {
                continue;
            }

            $newest[$phone] = $entry;
        }

        $rows = [];

        foreach ($newest as $phone => $entry) {
            $url = (string) $entry['url'];
            $errorType = $entry['error_type'] ?? null;

            $rows[$phone] = [
                'phone' => (string) $phone,
                'agent_slot_id' => $slotId,
                'phase' => IvacCallSummary::phase($url),
                'method' => (string) ($entry['method'] ?? ''),
                'url' => $url,
                'status_code' => isset($entry['status_code']) ? (int) $entry['status_code'] : null,
                'duration_ms' => isset($entry['duration_ms']) ? (int) $entry['duration_ms'] : null,
                'message' => IvacCallSummary::message($entry['response_body'] ?? null, $errorType, $url),
                'error_type' => $errorType,
                'logged_at' => $entry['logged_at'],
            ];
        }

        return $rows;
    }

    /**
     * Build a single conditional upsert.
     *
     * Every assignment is guarded by a comparison against the stored `logged_at` so a batch that
     * arrives late — retried by the shipper, or overtaken by a concurrent POST from the same slot
     * — can never replace a newer state with an older one. Doing it in one statement rather than
     * read-then-write also removes the race between two in-flight ingests for the same phone.
     *
     * `logged_at` is assigned last because MySQL evaluates the SET list left to right: updating it
     * first would make every guard after it compare the new value against itself.
     */
    private function upsertSql(int $rowCount): string
    {
        $placeholders = implode(', ', array_fill(
            0,
            $rowCount,
            '('.implode(', ', array_fill(0, count(self::COLUMNS) + 2, '?')).')',
        ));

        $guarded = [];
        foreach (self::COLUMNS as $column) {
            if ($column === 'phone' || $column === 'logged_at') {
                continue;
            }
            $guarded[] = "{$column} = IF(new.logged_at >= account_live_states.logged_at, new.{$column}, account_live_states.{$column})";
        }
        $guarded[] = 'updated_at = IF(new.logged_at >= account_live_states.logged_at, new.updated_at, account_live_states.updated_at)';
        $guarded[] = 'logged_at = GREATEST(new.logged_at, account_live_states.logged_at)';

        $columns = implode(', ', array_merge(self::COLUMNS, ['created_at', 'updated_at']));

        return "INSERT INTO account_live_states ({$columns}) VALUES {$placeholders} AS new ON DUPLICATE KEY UPDATE ".implode(', ', $guarded);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     * @return list<mixed>
     */
    private function bindings(array $rows): array
    {
        $now = now()->format('Y-m-d H:i:s');
        $bindings = [];

        foreach ($rows as $row) {
            foreach (self::COLUMNS as $column) {
                $bindings[] = $row[$column];
            }
            $bindings[] = $now;
            $bindings[] = $now;
        }

        return $bindings;
    }
}
