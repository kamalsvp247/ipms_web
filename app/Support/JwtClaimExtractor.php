<?php

namespace App\Support;

use Carbon\Carbon;

class JwtClaimExtractor
{
    /**
     * Decode a JWT payload (no signature verification) and extract iat/exp claims as Carbon datetimes.
     *
     * @return array{iat: ?Carbon, exp: ?Carbon}
     */
    public static function extract(string $jwt): array
    {
        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return ['iat' => null, 'exp' => null];
        }

        $payloadJson = base64_decode(strtr($parts[1], '-_', '+/'), true);

        if ($payloadJson === false) {
            return ['iat' => null, 'exp' => null];
        }

        $payload = json_decode($payloadJson, true);

        if (! is_array($payload)) {
            return ['iat' => null, 'exp' => null];
        }

        $tz = config('app.timezone');

        return [
            'iat' => isset($payload['iat']) && is_int($payload['iat'])
                ? Carbon::createFromTimestamp($payload['iat'], $tz)
                : null,
            'exp' => isset($payload['exp']) && is_int($payload['exp'])
                ? Carbon::createFromTimestamp($payload['exp'], $tz)
                : null,
        ];
    }
}
