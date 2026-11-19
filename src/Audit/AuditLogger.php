<?php

namespace GustavoLuis\ErpCore\Audit;

use GustavoLuis\ErpCore\Auth\AuthenticatedUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Escribe en el registro de auditoría. El modelo auditado debe implementar auditType() y auditOrganizationId()
 * (el trait Audited ya los trae).
 */
final class AuditLogger
{
    /**
     * Registra una acción. Si falla, lanza la excepción: se usa dentro de la misma transacción que el cambio,
     * así no puede existir un cambio sin su auditoría.
     *
     * @param array<string, mixed>|null $changes
     */
    public static function record(Model $auditable, string $action, ?array $changes = null): AuditLog
    {
        $request = request();

        return AuditLog::create([
            'organization_id' => $auditable->auditOrganizationId(),
            'user_id' => auth()->id(),
            'user_name' => AuthenticatedUser::currentName(),
            'action' => $action,
            'auditable_type' => $auditable->auditType(),
            'auditable_id' => $auditable->getKey(),
            'changes' => $changes,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') : null,
        ]);
    }

    /**
     * Igual que record(), pero sin lanzar. Para hechos que ya ocurrieron fuera de nuestro control (ej. un
     * comprobante que ARCA ya autorizó): la auditoría nunca debe tumbar esa operación. Si falla, se reporta
     * al log y a Sentry (si está) para poder reconstruir la entrada.
     *
     * @param array<string, mixed>|null $changes
     */
    public static function recordSafely(Model $auditable, string $action, ?array $changes = null): void
    {
        try {
            self::record($auditable, $action, $changes);
        } catch (Throwable $e) {
            Log::critical("No se pudo registrar la auditoría {$action} del {$auditable->auditType()} {$auditable->getKey()}: " . $e->getMessage());
            report($e);
        }
    }
}
