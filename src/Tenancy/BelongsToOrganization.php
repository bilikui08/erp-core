<?php

namespace GustavoLuis\ErpCore\Tenancy;

use GustavoLuis\ErpCore\Auth\AuthenticatedUser;
use LogicException;

/**
 * El registro pertenece a una EMPRESA (`organization_id`): dentro de un pedido autenticado solo se ven, y solo se pueden
 * crear o modificar, los de la empresa con la que trabaja la persona (claim `org` del token).
 *
 * Es la red de seguridad contra fugas entre empresas: no depende de que cada consulta se acuerde de filtrar.
 * Fuera de un pedido autenticado (consola, seeders, la descarga firmada de un PDF) no hay empresa activa y
 * no se filtra: quien consulta ahí debe acotar a mano (`withoutGlobalScope(OrganizationScope::class)` + where explícito).
 *
 * Todo modelo con columna `organization_id` DEBE usar este trait (cada servicio debería tener un test que lo verifique).
 */
trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope());

        static::saving(function ($model) {
            $current = AuthenticatedUser::currentOrganizationId();

            if ($current === null) {
                return;
            }

            $model->organization_id ??= $current;

            if ((int) $model->organization_id !== $current) {
                throw new LogicException('No se puede crear ni modificar un registro de otra empresa.');
            }
        });
    }
}
