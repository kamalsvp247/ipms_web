---
name: kb_encrypted_cast_decrypt_exception
description: Laravel 'encrypted' cast throws DecryptException on old APP_KEY — fix with custom Attribute accessor + try/catch returning null
metadata:
  type: feedback
---

Any model field cast as `'encrypted'` will throw `Illuminate\Contracts\Encryption\DecryptException` ("The MAC is invalid") when the DB row was encrypted with a previous APP_KEY. This crashes the API with a 500 and prevents the page from loading.

**Affected fields (confirmed):** `Account.password`, `User.plain_password`

**Fix pattern:** Remove `'field' => 'encrypted'` from `casts()` and add a custom Attribute with try/catch:

```php
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Facades\Crypt;

protected function plainPassword(): Attribute
{
    return Attribute::make(
        get: function ($value) {
            if ($value === null) return null;
            try {
                return Crypt::decrypt($value);
            } catch (\Exception $e) {
                return null;  // old APP_KEY — show blank, re-set via UI
            }
        },
        set: fn ($value) => $value !== null ? Crypt::encrypt($value) : null,
    );
}
```

**Why:** APP_KEY was rotated; old ciphertext is no longer valid. Returning null keeps the record readable; the user re-enters the password via the edit form to re-encrypt it.

**How to apply:** Whenever a page that loads model data returns 500 with "The MAC is invalid" in laravel.log, check if any field uses `'encrypted'` cast and apply this pattern.
