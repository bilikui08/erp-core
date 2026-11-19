<?php

namespace GustavoLuis\ErpCore\Audit;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Arr;

/**
 * Registra en la auditoría el alta, la edición, la baja y la restauración del modelo, con el antes y el después.
 *
 * El registro se escribe apenas ocurre el cambio y, si falla, lanza la excepción. Para que no pueda quedar un
 * cambio sin su auditoría, los repositorios envuelven cada operación en DB::transaction(): hacerlo siempre al
 * agregar un modelo auditado. Solo se detectan operaciones sobre modelos cargados (`$model->update()`,
 * `$model->delete()`): las consultas masivas (`Model::where(...)->update()`) no disparan eventos.
 *
 * El modelo debe definir auditType(): nombre corto del recurso ('customer', 'shipment', ...).
 */
trait Audited
{
    /** Columnas que no se consideran un cambio de negocio. */
    private const AUDIT_IGNORED = ['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'];

    abstract public function auditType(): string;

    /** Empresa dueña de los datos (id de auth-api). Los modelos que no tienen `organization_id` lo sobrescriben. */
    public function auditOrganizationId(): ?int
    {
        $owner = $this->getAttribute('organization_id');

        return $owner === null ? null : (int) $owner;
    }

    protected static function bootAudited(): void
    {
        static::created(function ($model) {
            AuditLogger::record($model, "{$model->auditType()}.created", ['values' => $model->auditSnapshot()]);
        });

        static::updated(function ($model) {
            $changes = $model->auditChanges();

            if ($changes !== []) {
                AuditLogger::record($model, "{$model->auditType()}.updated", ['changes' => $changes]);
            }
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::registerModelEvent('trashed', fn ($model) => AuditLogger::record($model, "{$model->auditType()}.deleted"));
            static::registerModelEvent('restored', fn ($model) => AuditLogger::record($model, "{$model->auditType()}.restored"));
            static::registerModelEvent('forceDeleted', fn ($model) => AuditLogger::record($model, "{$model->auditType()}.purged", ['values' => $model->auditSnapshot()]));
        } else {
            static::deleted(fn ($model) => AuditLogger::record($model, "{$model->auditType()}.deleted", ['values' => $model->auditSnapshot()]));
        }
    }

    /** @return array<string, mixed> */
    public function auditSnapshot(): array
    {
        return Arr::except($this->attributesToArray(), self::AUDIT_IGNORED);
    }

    /** @return array<string, array{old: mixed, new: mixed}> */
    public function auditChanges(): array
    {
        $previous = $this->getPrevious();
        $changes = [];

        foreach (Arr::except($this->getChanges(), self::AUDIT_IGNORED) as $field => $new) {
            $changes[$field] = ['old' => $previous[$field] ?? null, 'new' => $new];
        }

        return $changes;
    }
}
