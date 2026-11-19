<?php

namespace GustavoLuis\ErpCore\Tenancy;

use GustavoLuis\ErpCore\Auth\AuthenticatedUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/** Limita las consultas a la empresa activa del pedido autenticado (ver BelongsToOrganization). */
final class OrganizationScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $organizationId = AuthenticatedUser::currentOrganizationId();

        if ($organizationId !== null) {
            $builder->where($model->qualifyColumn('organization_id'), $organizationId);
        }
    }
}
