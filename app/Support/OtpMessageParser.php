<?php

namespace App\Support;

class OtpMessageParser
{
    public const IVACBD_PREFIX = '(IVACBD)';

    private const SECURITY_SEQUENCE_PREFIX = 'For security, type the following sequence when prompted';

    private const OTP_FOR_IVAC_PHRASE = 'One-Time Password for IVAC';

    private const WORD_TO_DIGIT = [
        'zero' => '0', 'one' => '1', 'two' => '2', 'three' => '3', 'four' => '4',
        'five' => '5', 'six' => '6', 'seven' => '7', 'eight' => '8', 'nine' => '9',
    ];

    public static function isIvacbd(string $message): bool
    {
        return str_contains($message, self::IVACBD_PREFIX)
            || stripos($message, self::SECURITY_SEQUENCE_PREFIX) !== false
            || stripos($message, self::OTP_FOR_IVAC_PHRASE) !== false;
    }

    public static function extractOtp(string $message): ?string
    {
        $wordOtp = self::extractWordFormOtp($message);
        if ($wordOtp !== null) {
            return $wordOtp;
        }

        if (preg_match('/\b\d{4,8}\b/', $message, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    private static function extractWordFormOtp(string $message): ?string
    {
        if (preg_match_all('/\b(zero|one|two|three|four|five|six|seven|eight|nine)\b/i', $message, $matches) < 1) {
            return null;
        }

        $words = $matches[1] ?? [];
        if (count($words) < 4) {
            return null;
        }

        $digits = '';
        foreach ($words as $word) {
            $digits .= self::WORD_TO_DIGIT[strtolower($word)];
        }

        return $digits;
    }
}
