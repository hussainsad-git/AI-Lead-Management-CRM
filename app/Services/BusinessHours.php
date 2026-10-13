<?php

namespace App\Services;

use App\Support\Tenancy\CurrentOrganization;
use Carbon\CarbonInterface;

class BusinessHours
{
    public const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    public const DEFAULT = ['mon' => ['09:00', '18:00'], 'tue' => ['09:00', '18:00'], 'wed' => ['09:00', '18:00'], 'thu' => ['09:00', '18:00'],
        'fri' => ['09:00', '18:00'], 'sat' => ['10:00', '16:00'], 'sun' => null];

    public function __construct(private OrganizationSettings $settings, private CurrentOrganization $org) {}

    public function schedule(): array
    {
        return array_replace(self::DEFAULT, (array) ($this->settings->get('business_hours') ?? []));
    }

    public function isOpen(?CarbonInterface $at = null): bool
    {
        $local = ($at ?? now())->copy()->setTimezone($this->org->require()->timezone);
        $day = self::DAYS[$local->dayOfWeekIso - 1];
        $span = $this->schedule()[$day] ?? null;
        if (! $span || count($span) !== 2) {
            return false;
        }
        $t = $local->format('H:i');

        return $t >= $span[0] && $t < $span[1];
    }
}
