<?php

namespace App\Support;

use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonImmutable;
use Throwable;

/** Wall-clock inputs from forms (no offset) are interpreted in the user's/organization's time zone. */
final class LocalTime
{
    public static function timezone(): string
    {
        return auth()->user()?->timezone ?: (app(CurrentOrganization::class)->get()?->timezone ?? config('app.timezone'));
    }

    public static function toUtc(mixed $value): mixed
    {
        if (! is_string($value) || trim($value) === '') {
            return $value;
        }
        try {
            $hasOffset = (bool) preg_match('/(Z|[+-]\d{2}:?\d{2})$/i', trim($value));
            $c = $hasOffset ? CarbonImmutable::parse($value) : CarbonImmutable::parse($value, self::timezone());

            return $c->utc()->toIso8601ZuluString();
        } catch (Throwable) {
            return $value; // let validation report it
        }
    }
}
