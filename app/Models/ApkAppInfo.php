<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApkAppInfo extends Model
{
    protected $fillable = [
        'app_title',
        'tagline',
        'description',
        'package_name',
        'developer_name',
        'developer_email',
        'developer_website',
        'logo_path',
        'features',
        'is_published',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'is_published' => 'boolean',
        ];
    }

    public static function instance(): self
    {
        return self::firstOrCreate([], ['app_title' => 'Blitz SMS Forwarder', 'is_published' => true]);
    }
}
