<?php

namespace GustavoLuis\ErpCore\Audit;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consulta de la auditoría de una empresa, de lo más nuevo a lo más viejo. Cada servicio la usa desde su endpoint
 * `GET /audit-logs`; el permiso para verla (y si ve todo o solo lo suyo) lo decide cada servicio.
 */
final class AuditLogQuery
{
    /**
     * Una empresa nunca ve la auditoría de datos ajenos. Con $onlyUserId solo las acciones de esa persona.
     *
     * @param array{action?: ?string, auditable_type?: ?string, auditable_id?: ?int, user_id?: ?int, user?: ?string, date_from?: ?string, date_to?: ?string} $filters
     */
    public static function list(int $organizationId, array $filters = [], ?int $onlyUserId = null, int $perPage = 25): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('organization_id', $organizationId)
            // El filtro por persona va antes que cualquier búsqueda: quien no puede ver todo ve solo lo que hizo
            ->when($onlyUserId !== null, fn ($q) => $q->where('user_id', $onlyUserId))
            ->when($filters['action'] ?? null, fn ($q, $v) => $q->where('action', $v))
            ->when($filters['auditable_type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['auditable_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['user'] ?? null, fn ($q, $v) => self::whereUserMatches($q, (string) $v))
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v . ' 00:00:00'))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v . ' 23:59:59'))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Texto libre: coincide con parte del nombre (foto fija en `user_name`) y, si es un número o "Usuario N"
     * (como se muestran los registros sin nombre), también con el id.
     */
    private static function whereUserMatches(Builder $query, string $text): void
    {
        $like = '%' . addcslashes($text, '\\%_') . '%';
        $id = preg_match('/^(?:usuario\s+)?(\d{1,18})$/i', $text, $m) ? (int) $m[1] : null;

        $query->where(function (Builder $q) use ($like, $id) {
            $q->where('user_name', 'like', $like);

            if ($id !== null) {
                $q->orWhere('user_id', $id);
            }
        });
    }
}
