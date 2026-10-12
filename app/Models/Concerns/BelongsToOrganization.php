<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Models\Scopes\OrganizationScope;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope);

        static::creating(function ($model) {
            $current = app(CurrentOrganization::class)->id();
            if ($model->organization_id === null) {
                $model->organization_id = $current ?? throw new LogicException('Cannot create '.static::class.' without an organization context.');
            } elseif ($current !== null && (int) $model->organization_id !== $current) {
                throw new LogicException('Cross-organization write blocked for '.static::class.'.');
            }
        });

        static::updating(function ($model) {
            if ($model->isDirty('organization_id')) {
                throw new LogicException('organization_id is immutable.');
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
