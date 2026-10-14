<?php

namespace App\Services;

use App\Models\OrganizationSetting;

/** Typed access to per-organization settings with defaults. */
class OrganizationSettings
{
    public const DEFAULTS = [
        // which identifiers satisfy "has a contact identifier" on manual entry / import
        'required_identifier' => 'phone_or_whatsapp', // phone_or_whatsapp | any
        'allow_duplicate_open_leads' => false,
        'first_contact_sla_minutes' => 30,
        'retention_archive_after_days' => null,   // archive leads closed (won/lost) for this long; null = never
        'retention_purge_webhooks_days' => 30,    // delete processed webhook payloads
        'retention_purge_notifications_days' => 90,
        'retention_purge_ai_days' => 180,         // delete AI interaction prompts/outputs (metrics rows stay)
        'retention_purge_automation_days' => 180, // delete automation execution history
    ];

    /** @var array<int, array> cache keyed by organization id */
    private array $cache = [];

    public function all(): array
    {
        $orgId = app(\App\Support\Tenancy\CurrentOrganization::class)->id() ?? 0;
        if (! isset($this->cache[$orgId])) {
            $values = self::DEFAULTS;
            foreach (OrganizationSetting::all() as $s) {
                $values[$s->key] = $s->value['v'] ?? null;
            }
            $this->cache[$orgId] = $values;
        }

        return $this->cache[$orgId];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        OrganizationSetting::updateOrCreate(['key' => $key], ['value' => ['v' => $value]]);
        $this->cache = [];
    }
}
