<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A message broadcast in the app header to every signed-in user.
 *
 * Several notices can be live at once — the header scrolls every enabled row as one
 * marquee, in sort order.
 */
class Notice extends Model
{
    protected $fillable = [
        'text',
        'is_enabled',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('is_enabled', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }

    /**
     * The texts shown in the header, in display order.
     *
     * @return array<int, string>
     */
    public static function activeTexts(): array
    {
        return static::query()
            ->enabled()
            ->ordered()
            ->pluck('text')
            ->map(fn (string $text): string => trim($text))
            ->filter(fn (string $text): bool => $text !== '')
            ->values()
            ->all();
    }
}
