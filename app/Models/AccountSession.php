<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountSession extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'phone',
        'signed_in_server_time',
        'jwt_token',
        'jwt_generated_at',
        'jwt_expires_at',
        'is_otp_verified',
        'otp_code',
        'otp_verified_server_time',
        'slot_id',
        'token_type',
        'expires_at',
        'user_id',
        'request_id',
        'last_booking_config',
        'last_file_overview',
        'reserved_visa_type',
        'reserved_applicants',
        'roles',
        'status_code',
        'message',
        'success_flag',
        'server_time',
    ];

    protected function casts(): array
    {
        return [
            'last_booking_config' => 'array',
            'last_file_overview' => 'array',
            'roles' => 'json',
            'success_flag' => 'boolean',
            'is_otp_verified' => 'boolean',
            'jwt_generated_at' => 'datetime',
            'jwt_expires_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
