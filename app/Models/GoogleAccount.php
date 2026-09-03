<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleAccount extends Model
{
    protected $fillable = [
        'user_id',
        'email_address',
        'access_token',
        'refresh_token',
        'expires_at',
        'scope',
        'history_id',
        'watch_expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'watch_expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(GmailMessage::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isWatchExpiringSoon(): bool
    {
        return $this->watch_expires_at === null || $this->watch_expires_at->isBefore(now()->addHours(48));
    }
}
