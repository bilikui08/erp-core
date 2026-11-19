<?php

namespace GustavoLuis\ErpCore\Authorship;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Baja lógica que además registra quién la hizo (`deleted_by`).
 *
 * `deleted_at` y `deleted_by` se graban en la MISMA sentencia: no puede quedar una baja sin autor.
 * Siempre borrar con el modelo cargado (`$model->delete()`): `Model::where(...)->delete()` no registra `deleted_by`.
 * Al restaurar se limpia `deleted_by`.
 */
trait SoftDeletesWithAuthor
{
    use SoftDeletes;

    protected static function bootSoftDeletesWithAuthor(): void
    {
        static::restoring(function ($model) {
            $model->deleted_by = null;
        });
    }

    /** Igual que SoftDeletes::runSoftDelete(), agregando `deleted_by` a la misma actualización. */
    protected function runSoftDelete()
    {
        $query = $this->setKeysForSaveQuery($this->newModelQuery());

        $time = $this->freshTimestamp();
        $userId = auth()->id();

        $columns = [
            $this->getDeletedAtColumn() => $this->fromDateTime($time),
            'deleted_by' => $userId,
        ];

        $this->{$this->getDeletedAtColumn()} = $time;
        $this->deleted_by = $userId;

        if ($this->usesTimestamps() && ! is_null($this->getUpdatedAtColumn())) {
            $this->{$this->getUpdatedAtColumn()} = $time;

            $columns[$this->getUpdatedAtColumn()] = $this->fromDateTime($time);
        }

        $query->update($columns);

        $this->syncOriginalAttributes(array_keys($columns));

        $this->fireModelEvent('trashed', false);
    }
}
