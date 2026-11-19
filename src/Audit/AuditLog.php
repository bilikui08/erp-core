<?php

namespace GustavoLuis\ErpCore\Audit;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Entrada del registro de auditoría. Es de solo agregar: la aplicación nunca la modifica ni la borra.
 * Se crea únicamente a través de AuditLogger.
 *
 * Aviso: esto protege contra errores de la aplicación. Quien tenga acceso directo a la base puede
 * alterarla (sin triggers ni cadena de hashes no hay evidencia de manipulación).
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'audit_logs';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['changes' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('El registro de auditoría es de solo lectura: no se puede modificar.');
        });

        static::deleting(function () {
            throw new LogicException('El registro de auditoría es de solo lectura: no se puede borrar.');
        });
    }
}
