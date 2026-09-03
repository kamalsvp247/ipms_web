<?php

namespace App\Models;

use App\Enums\CaptchaTokenType;
use Illuminate\Database\Eloquent\Model;

class CaptchaToken extends Model
{
    protected $fillable = [
        'provider_id',
        'type',
        'token',
    ];

    protected function casts(): array
    {
        return [
            'type' => CaptchaTokenType::class,
        ];
    }

    public function provider()
    {
        return $this->belongsTo(CaptchaProvider::class, 'provider_id');
    }
}
