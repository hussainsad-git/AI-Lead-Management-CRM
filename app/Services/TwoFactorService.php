<?php

namespace App\Services;

use App\Exceptions\DomainRuleException;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TwoFactorService
{
    public function __construct(private AuditLogger $audit) {}

    /** Starts (or restarts) enrolment: new secret, not yet confirmed. */
    public function begin(User $user): array
    {
        if ($user->totp_confirmed_at) {
            throw new DomainRuleException('Two-factor authentication is already enabled. Disable it first to re-enrol.', 'already_enabled', 409);
        }
        $secret = Totp::generateSecret();
        $user->forceFill(['totp_secret' => $secret, 'totp_last_step' => null])->save();
        $issuer = $user->organization?->name ?: 'LeadFlow';

        return ['secret' => $secret, 'uri' => Totp::uri($secret, $user->email, $issuer), 'issuer' => $issuer];
    }

    /** @return string[] plaintext recovery codes (shown once) */
    public function confirm(User $user, string $code): array
    {
        if (! $user->totp_secret || $user->totp_confirmed_at) {
            throw new DomainRuleException('Start enrolment first.', 'not_enrolling', 409);
        }
        $step = Totp::verify($user->totp_secret, $code);
        if ($step === null) {
            throw new DomainRuleException('That code is not valid. Check the time on your phone and try the next code.', 'invalid_code', 422);
        }
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['totp_confirmed_at' => now(), 'totp_last_step' => $step, 'recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes)])->save();
        $this->audit->log('auth.2fa_enabled', $user);

        return $codes;
    }

    public function disable(User $user, ?User $actor = null): void
    {
        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null, 'recovery_codes' => null])->save();
        $this->audit->log($actor && $actor->id !== $user->id ? 'auth.2fa_reset_by_admin' : 'auth.2fa_disabled', $user);
    }

    /** Verifies a TOTP or recovery code at login; consumes recovery codes; blocks TOTP replay. */
    public function verifyLogin(User $user, string $code): bool
    {
        $code = trim($code);
        $step = Totp::verify((string) $user->totp_secret, $code, $user->totp_last_step);
        if ($step !== null) {
            $user->forceFill(['totp_last_step' => $step])->saveQuietly();

            return true;
        }
        $normalized = strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code));
        foreach ((array) $user->recovery_codes as $i => $hash) {
            if (strlen($normalized) === 10 && Hash::check($normalized, $hash)) {
                $codes = $user->recovery_codes;
                unset($codes[$i]);
                $user->forceFill(['recovery_codes' => array_values($codes)])->saveQuietly();
                $this->audit->log('auth.recovery_code_used', $user, [], ['remaining' => count($codes)]);

                return true;
            }
        }

        return false;
    }

    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['recovery_codes' => array_map(fn ($c) => Hash::make(str_replace('-', '', $c)), $codes)])->save();
        $this->audit->log('auth.recovery_codes_regenerated', $user);

        return $codes;
    }

    /** @return string[] ten codes like "k7m2-9qpd-4x" (10 alphanumerics, dashes for readability) */
    private function newRecoveryCodes(): array
    {
        $out = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = strtolower(Str::random(10));
            $out[] = substr($raw, 0, 4).'-'.substr($raw, 4, 4).'-'.substr($raw, 8, 2);
        }

        return $out;
    }
}
