<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CaptchaTransformSeed extends Model
{
    protected $fillable = ['token_type', 'seed', 'offset', 'length', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'offset' => 'integer',
            'length' => 'integer',
        ];
    }

    /**
     * Returns the active row for the given token type, or null if none is set.
     */
    public static function activeForType(string $type): ?self
    {
        return static::where('token_type', $type)->where('is_active', true)->first();
    }

    /**
     * Activates this record and deactivates all others of the same token type.
     *
     * Both writes use the query builder rather than $this->update() so the activation
     * always persists: when this row is already the active one in memory, $this->update()
     * would see no dirty change and skip the write, leaving every row deactivated by the
     * preceding mass update.
     */
    public function activate(): void
    {
        static::where('token_type', $this->token_type)
            ->whereKeyNot($this->getKey())
            ->update(['is_active' => false]);
        static::whereKey($this->getKey())->update(['is_active' => true]);
        $this->is_active = true;
    }
}
