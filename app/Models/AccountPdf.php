<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single applicant PDF (Base64) belonging to an account. Split out of the accounts table so the
 * large payload is loaded only on demand (edit modal, bot fetch), never on account list queries.
 *
 * `base64` is the pristine original the user uploaded; `optimized_base64` is the smaller
 * Ghostscript-optimized copy the bot uploads to IVAC (null until optimized). The original is never
 * mutated so the optimized copy can always be re-derived (see App\Services\Pdf\PdfOptimizer).
 */
class AccountPdf extends Model
{
    protected $fillable = [
        'account_id',
        'name',
        'application_id',
        'base64',
        'optimized_base64',
        'original_size',
        'optimized_size',
        'is_primary',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'original_size' => 'integer',
        'optimized_size' => 'integer',
    ];

    /**
     * The Base64 the bot should upload to IVAC: the optimized copy when available, else the original.
     */
    protected function deliverableBase64(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->optimized_base64 ?? $this->base64,
        );
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
