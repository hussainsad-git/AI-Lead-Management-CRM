<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** A date range resolved in the organization's time zone and expressed in UTC for queries. */
final class DateRange
{
    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'this_month', 'previous_month', 'custom', 'all_time'];

    private function __construct(
        public readonly ?CarbonImmutable $start,  // UTC, inclusive
        public readonly ?CarbonImmutable $end,    // UTC, exclusive
        public readonly string $preset,
        public readonly string $timezone,
    ) {}

    public static function resolve(string $preset, string $timezone, ?string $from = null, ?string $to = null): self
    {
        $now = CarbonImmutable::now($timezone);
        [$s, $e] = match ($preset) {
            'today' => [$now->startOfDay(), $now->startOfDay()->addDay()],
            'yesterday' => [$now->startOfDay()->subDay(), $now->startOfDay()],
            'last_7_days' => [$now->startOfDay()->subDays(6), $now->startOfDay()->addDay()],
            'last_30_days' => [$now->startOfDay()->subDays(29), $now->startOfDay()->addDay()],
            'this_month' => [$now->startOfMonth(), $now->startOfMonth()->addMonth()],
            'previous_month' => [$now->startOfMonth()->subMonth(), $now->startOfMonth()],
            'custom' => self::custom($from, $to, $timezone),
            'all_time' => [null, null],
            default => throw new \App\Exceptions\DomainRuleException('Unknown date range preset.', 'invalid_date_range'),
        };

        return new self($s?->utc(), $e?->utc(), $preset, $timezone);
    }

    private static function custom(?string $from, ?string $to, string $tz): array
    {
        if (! $from || ! $to) {
            throw new \App\Exceptions\DomainRuleException('Custom range requires from and to dates.', 'invalid_date_range');
        }
        $s = CarbonImmutable::createFromFormat('!Y-m-d', $from, $tz);
        $e = CarbonImmutable::createFromFormat('!Y-m-d', $to, $tz);
        if (! $s || ! $e || $e->lt($s)) {
            throw new \App\Exceptions\DomainRuleException('Invalid custom date range.', 'invalid_date_range');
        }
        if ($s->diffInDays($e) > 731) {
            throw new \App\Exceptions\DomainRuleException('Custom range cannot exceed two years.', 'invalid_date_range');
        }

        return [$s, $e->addDay()];
    }

    /** Local calendar dates covered (for daily spend), inclusive. */
    public function localDates(): array
    {
        if (! $this->start) {
            return [null, null];
        }

        return [$this->start->setTimezone($this->timezone)->toDateString(), $this->end->setTimezone($this->timezone)->subDay()->toDateString()];
    }

    public function toArray(): array
    {
        [$from, $to] = $this->localDates();

        return ['preset' => $this->preset, 'from' => $from, 'to' => $to, 'timezone' => $this->timezone,
            'start_utc' => $this->start?->toIso8601ZuluString(), 'end_utc' => $this->end?->toIso8601ZuluString()];
    }
}
