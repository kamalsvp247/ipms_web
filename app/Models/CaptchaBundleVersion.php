<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A stored, activatable IVAC captcha bundle: the downloaded JS file plus the
 * encrypt_meta describing how to run it. Activating a version mirrors its file +
 * meta into the fixed paths the live-bundle sidecar reads.
 */
class CaptchaBundleVersion extends Model
{
    protected $fillable = [
        'bundle_filename',
        'bundle_url',
        'bundle_hash',
        'download_started_at',
        'download_duration_ms',
        'meta_json',
        'login_version',
        'login_skip',
        'login_enc_len',
        'login_secret',
        'reserve_version',
        'reserve_skip',
        'reserve_enc_len',
        'reserve_secret',
        'charset',
        'extraction_ok',
        'processing_completed_at',
        'processing_duration_ms',
        'is_active',
        'label',
        'activated_at',
        'healthy_at',
        'reload_duration_ms',
    ];

    protected function casts(): array
    {
        return [
            'meta_json' => 'array',
            'extraction_ok' => 'boolean',
            'is_active' => 'boolean',
            'login_version' => 'integer',
            'login_skip' => 'integer',
            'login_enc_len' => 'integer',
            'reserve_version' => 'integer',
            'reserve_skip' => 'integer',
            'reserve_enc_len' => 'integer',
            'download_started_at' => 'datetime',
            'download_duration_ms' => 'integer',
            'processing_completed_at' => 'datetime',
            'processing_duration_ms' => 'integer',
            'activated_at' => 'datetime',
            'healthy_at' => 'datetime',
            'reload_duration_ms' => 'integer',
        ];
    }

    /**
     * The currently active version, or null if none.
     */
    public static function current(): ?self
    {
        return static::where('is_active', true)->first();
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Activate this version and deactivate all others.
     *
     * Both writes use the query builder rather than $this->update() so the activation
     * always persists: when this row is already the active one in memory, $this->update()
     * would see no dirty change and skip the write, leaving every row deactivated by the
     * preceding mass update.
     */
    public function activate(): void
    {
        static::whereKeyNot($this->getKey())->update(['is_active' => false]);
        static::whereKey($this->getKey())->update(['is_active' => true, 'activated_at' => now()]);
        $this->is_active = true;
        $this->activated_at = now();
    }
}
