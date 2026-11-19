<?php

namespace GustavoLuis\ErpCore\Tests\Support;

use GustavoLuis\ErpCore\Authorship\RecordsAuthorship;
use GustavoLuis\ErpCore\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Registro inmutable (como un comprobante): solo guarda quién lo creó y su nombre. */
class Receipt extends Model
{
    use BelongsToOrganization;
    use RecordsAuthorship;

    protected $guarded = [];

    public function storesAuthorName(): bool
    {
        return true;
    }

    public function recordsEditor(): bool
    {
        return false;
    }
}
