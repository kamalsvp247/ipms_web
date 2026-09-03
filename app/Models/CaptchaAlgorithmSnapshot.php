<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptchaAlgorithmSnapshot extends Model
{
    protected $fillable = [
        'bundle_url',
        'bundle_filename',
        'offset',
        'length',
        'magic_numbers_match',
        'login_magic_match',
        'charset',
        'secret',
        'login_secret',
        'skip',
        'encrypt_len',
        'login_skip',
        'login_enc_len',
        'js_function',
        'captcha_encryption',
        'fingerprint',
    ];

    protected function casts(): array
    {
        return [
            'magic_numbers_match' => 'boolean',
            'login_magic_match' => 'boolean',
            'offset' => 'integer',
            'length' => 'integer',
            'skip' => 'integer',
            'encrypt_len' => 'integer',
            'login_skip' => 'integer',
            'login_enc_len' => 'integer',
        ];
    }

    /**
     * Compute a fingerprint from key fields for change detection.
     */
    public static function computeFingerprint(array $data): string
    {
        $key = implode('|', [
            $data['offset'] ?? '',
            $data['length'] ?? '',
            $data['charset'] ?? '',
            $data['secret'] ?? '',
            $data['skip'] ?? '',
            $data['encrypt_len'] ?? '',
            $data['login_secret'] ?? '',
            $data['login_skip'] ?? '',
            $data['login_enc_len'] ?? '',
        ]);

        return hash('sha256', $key);
    }

    /**
     * Get all snapshots for history display (last 20).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, self>
     */
    public static function history(): \Illuminate\Database\Eloquent\Collection
    {
        return static::query()
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
    }

    /**
     * Compare this snapshot against an array of new analysis data.
     * Returns list of changed fields with old/new values.
     *
     * @return array<string, array{old: mixed, new: mixed}>
     */
    public function diffAgainst(array $newData): array
    {
        $fields = ['offset', 'length', 'charset', 'secret', 'skip', 'encrypt_len', 'login_secret', 'login_skip', 'login_enc_len'];
        $changes = [];

        foreach ($fields as $field) {
            $oldValue = $this->{$field};
            $newValue = $newData[$field] ?? null;

            if ($oldValue !== null && $newValue !== null && (string) $oldValue !== (string) $newValue) {
                $changes[$field] = ['old' => $oldValue, 'new' => $newValue];
            }
        }

        return $changes;
    }
}
