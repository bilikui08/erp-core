<?php

namespace GustavoLuis\ErpCore\Authorship;

use GustavoLuis\ErpCore\Auth\AuthenticatedUser;

/**
 * Registra quién creó (`created_by`) y quién modificó por última vez (`updated_by`) un registro.
 *
 * Guarda el id del usuario autenticado (el `sub` del token de auth-api). Fuera de una petición
 * autenticada (seeders, consola) no hay usuario y las columnas quedan vacías.
 * `updated_by` queda vacío hasta la primera edición. Nunca se asignan a mano.
 */
trait RecordsAuthorship
{
    protected static function bootRecordsAuthorship(): void
    {
        static::creating(function ($model) {
            $userId = auth()->id();

            if ($userId !== null) {
                $model->created_by ??= $userId;

                if ($model->storesAuthorName()) {
                    $model->created_by_name ??= AuthenticatedUser::currentName();
                }
            }
        });

        static::updating(function ($model) {
            $userId = auth()->id();

            if ($userId !== null && $model->recordsEditor() && $model->isDirty()) {
                $model->updated_by = $userId;
            }
        });
    }

    /** Un modelo puede guardar también el nombre de quien lo creó (foto fija, columna `created_by_name`). */
    public function storesAuthorName(): bool
    {
        return false;
    }

    /** Los modelos inmutables (por ejemplo, comprobantes) sobrescriben esto: solo registran quién los creó. */
    public function recordsEditor(): bool
    {
        return true;
    }
}
