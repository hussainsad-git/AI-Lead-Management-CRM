<?php

namespace App\Models\Scopes;

use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Fails closed: with no organization bound, tenant queries return nothing. */
class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $orgId = app(CurrentOrganization::class)->id();
        $column = $model->qualifyColumn('organization_id');

        $orgId === null
            ? $builder->whereRaw('1 = 0')
            : $builder->where($column, $orgId);
    }
}
