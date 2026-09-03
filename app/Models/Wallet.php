<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Crypt;

class Wallet extends Model
{
    /** @use HasFactory<\Database\Factories\WalletFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'method',
        'wallet_number',
        'pin',
        'label',
    ];

    /**
     * The PIN is a spending credential — keep it out of every array/JSON payload so it never rides
     * along on the list response. WalletController::show() merges it back in on demand for editing,
     * the same way Account::auto_payment_pin is handled.
     *
     * @var list<string>
     */
    protected $hidden = [
        'pin',
    ];

    /**
     * Mirrors Account::autoPaymentPin(): encrypted at rest, and a decrypt failure (typically a
     * restored backup under a different APP_KEY) yields null rather than throwing.
     */
    protected function pin(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($value === null) {
                    return null;
                }
                try {
                    return Crypt::decrypt($value);
                } catch (\Exception $e) {
                    return null;
                }
            },
            set: fn ($value) => $value !== null ? Crypt::encrypt($value) : null,
        );
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
