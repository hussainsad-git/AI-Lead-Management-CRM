<?php

namespace App\Support;

/** RFC 6238 TOTP (SHA-1, 6 digits, 30 s) + RFC 4648 base32, compatible with Google/Microsoft Authenticator, Authy, 1Password. */
class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestep = null, int $digits = 6): string
    {
        $timestep ??= intdiv(time(), 30);
        $hash = hash_hmac('sha1', pack('J', $timestep), self::base32Decode($secret), true);
        $offset = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$offset]) & 0x7F) << 24) | (ord($hash[$offset + 1]) << 16) | (ord($hash[$offset + 2]) << 8) | ord($hash[$offset + 3]);

        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** @return int|null the matching timestep (for replay protection), null when invalid. Window ±1 step. */
    public static function verify(string $secret, string $code, ?int $notBefore = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) {
            return null;
        }
        $now = intdiv(time(), 30);
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            if ($notBefore !== null && $step <= $notBefore) {
                continue;
            }
            if (hash_equals(self::code($secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        // Defaults (SHA1, 6 digits, 30 s) are omitted to keep the QR small; every mainstream app assumes them.
        $issuer = mb_substr(trim($issuer), 0, 24);
        $uri = fn ($iss) => 'otpauth://totp/'.rawurlencode($iss).':'.rawurlencode($account).'?secret='.$secret.'&issuer='.rawurlencode($iss);

        return strlen($uri($issuer)) <= 200 ? $uri($issuer) : $uri('LeadFlow');
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s));
        $bits = '';
        foreach (str_split($s) as $ch) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $ch)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
