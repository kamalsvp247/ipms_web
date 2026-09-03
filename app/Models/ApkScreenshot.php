<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApkScreenshot extends Model
{
    protected $fillable = ['path', 'caption', 'sort_order'];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
