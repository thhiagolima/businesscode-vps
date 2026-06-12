<?php
namespace App\Services\Messaging;

class PhoneNormalizer
{
    public static function e164(string $raw, string $defaultCountryCode = '55'): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new \InvalidArgumentException('Empty phone');
        }

        $digits = preg_replace('/\D/', '', $raw);
        if ($digits === '') {
            throw new \InvalidArgumentException("Invalid phone: {$raw}");
        }

        $startsWithPlus = str_starts_with($raw, '+');
        $startsWithIntlDoubleZero = ! $startsWithPlus && str_starts_with($digits, '00');

        if ($startsWithPlus || $startsWithIntlDoubleZero) {
            // Already international; strip ALL leading zeros (covers "+00..." and "0055...")
            $digits = ltrim($digits, '0');
            if ($digits === '') {
                throw new \InvalidArgumentException("Invalid phone: {$raw}");
            }
            $candidate = '+' . $digits;
        } else {
            // Detect international format that omits the + sign:
            //   55 + DDD(2) + 8-or-9-digit subscriber = 12 or 13 digits starting with default country code.
            $cc = $defaultCountryCode;
            $expectedFull = strlen($cc) + 10; // DDD(2) + 8 = 10 (legacy)
            $expectedFullMobile = strlen($cc) + 11; // DDD(2) + 9 = 11 (mobile)

            $looksInternational = str_starts_with($digits, $cc)
                && in_array(strlen($digits), [$expectedFull, $expectedFullMobile], true);

            if ($looksInternational) {
                $candidate = '+' . $digits;
            } else {
                $candidate = '+' . $cc . $digits;
            }
        }

        if (! preg_match('/^\+[1-9]\d{6,14}$/', $candidate)) {
            throw new \InvalidArgumentException("Invalid E.164: {$raw}");
        }

        return $candidate;
    }

    public static function emailLower(string $raw): string
    {
        $clean = mb_strtolower(trim($raw));
        if (! filter_var($clean, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email: {$raw}");
        }
        return $clean;
    }
}
