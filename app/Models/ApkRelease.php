<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApkRelease extends Model
{
    protected $fillable = [
        'version_name',
        'version_code',
        'file_path',
        'file_name',
        'file_size',
        'checksum_sha256',
        'changelog',
        'min_android',
        'is_active',
        'download_count',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'file_size' => 'integer',
            'is_active' => 'boolean',
            'download_count' => 'integer',
            'released_at' => 'datetime',
        ];
    }

    public static function active(): ?self
    {
        return self::where('is_active', true)->latest('released_at')->first();
    }

    public function activate(): void
    {
        self::where('id', '!=', $this->id)->update(['is_active' => false]);
        $this->update(['is_active' => true]);
    }
}
