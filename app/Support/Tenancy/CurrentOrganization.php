<?php

namespace App\Support\Tenancy;

use App\Models\Organization;
use RuntimeException;

/**
 * Holds the organization for the current request/job. Bound as a singleton.
 * The value is only ever set from the authenticated user (middleware) or explicitly
 * by trusted server code (seeders, jobs) — never from client input.
 */
class CurrentOrganization
{
    private ?Organization $organization = null;

    public function set(?Organization $organization): void
    {
        $this->organization = $organization;
    }

    public function get(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->id;
    }

    public function require(): Organization
    {
        return $this->organization ?? throw new RuntimeException('No organization bound to the current context.');
    }

    /** Run a callback with a specific organization bound, restoring the previous one afterwards. */
    public function runAs(Organization $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = $organization;
        try {
            return $callback($organization);
        } finally {
            $this->organization = $previous;
        }
    }
}
