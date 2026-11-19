<?php

namespace GustavoLuis\ErpCore\Tests\Support;

use GustavoLuis\ErpCore\Audit\Audited;
use GustavoLuis\ErpCore\Authorship\RecordsAuthorship;
use GustavoLuis\ErpCore\Authorship\SoftDeletesWithAuthor;
use GustavoLuis\ErpCore\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Modelo de ejemplo con todo lo del paquete: empresa, autoría, baja lógica con autor y auditoría. */
class Widget extends Model
{
    use Audited;
    use BelongsToOrganization;
    use RecordsAuthorship;
    use SoftDeletesWithAuthor;

    protected $guarded = [];

    public function auditType(): string
    {
        return 'widget';
    }
}
