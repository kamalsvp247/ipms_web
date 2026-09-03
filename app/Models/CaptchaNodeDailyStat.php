<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One row per node per day of in-house solve outcomes.
 *
 * Written on every completed lease, because captcha_requests is purged minutes later and
 * a node's self-reported counters reset on restart.
 */
class CaptchaNodeDailyStat extends Model
{
    /** @use HasFactory<\Database\Factories\CaptchaNodeDailyStatFactory> */
    use HasFactory;

    protected $fillable = [
        'date',
        'captcha_node_id',
        'solved',
        'failed',
        'total_ms',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'solved' => 'integer',
            'failed' => 'integer',
            'total_ms' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<CaptchaNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(CaptchaNode::class, 'captcha_node_id');
    }

    /**
     * Add one outcome to today's tally for a node.
     *
     * An upsert with a raw increment rather than read-modify-write: several nodes report
     * results concurrently, and a fetch-then-save would lose counts under that overlap.
     */
    public static function record(?int $nodeId, bool $solved, ?int $durationMs = null): void
    {
        $ms = max(0, (int) $durationMs);

        DB::table((new self)->getTable())->upsert(
            [[
                'date' => Carbon::today()->toDateString(),
                'captcha_node_id' => $nodeId,
                'solved' => $solved ? 1 : 0,
                'failed' => $solved ? 0 : 1,
                'total_ms' => $solved ? $ms : 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]],
            ['date', 'captcha_node_id'],
            [
                'solved' => DB::raw('solved + VALUES(solved)'),
                'failed' => DB::raw('failed + VALUES(failed)'),
                'total_ms' => DB::raw('total_ms + VALUES(total_ms)'),
                'updated_at' => DB::raw('VALUES(updated_at)'),
            ],
        );
    }
}
