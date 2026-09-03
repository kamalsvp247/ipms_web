<?php

namespace App\Models;

use App\Enums\CaptchaProviderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaptchaProvider extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'enabled',
        'api_key',
        'solver_threads',
        'params',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    protected function casts(): array
    {
        return [
            'type' => CaptchaProviderType::class,
            'enabled' => 'boolean',
            'params' => 'json',
        ];
    }
}
