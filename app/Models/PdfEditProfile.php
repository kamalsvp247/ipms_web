<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PdfEditProfile extends Model
{
    protected $fillable = [
        'phone',
        'surname',
        'given_name',
        'passport_no',
        'pdf_phone',
        'email',
    ];
}
