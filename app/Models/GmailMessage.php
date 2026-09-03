<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GmailMessage extends Model
{
    protected $fillable = [
        'google_account_id',
        'gmail_id',
        'thread_id',
        'from',
        'to_address',
        'phone',
        'subject',
        'snippet',
        'received_at',
        'is_unread',
    ];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'is_unread' => 'boolean',
        ];
    }

    public function googleAccount(): BelongsTo
    {
        return $this->belongsTo(GoogleAccount::class);
    }
}
