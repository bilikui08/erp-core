# gustavoluis/erp-core

Piezas comunes de los servicios del ERP (billing, shipping, ...). Cada servicio las instala con Composer y no las reescribe.

Paquete **privado**: no se publica en Packagist.

## Qué incluye

| Módulo | Clases | Para qué |
|---|---|---|
| **Autenticación** | `Auth\PassportJwtGuard`, `Auth\AuthenticatedUser`, `Http\Middleware\RequirePermission` | Valida los tokens de auth-api con la clave pública (RS256), arma la persona en memoria y chequea permisos por ruta |
| **Empresas** | `Tenancy\BelongsToOrganization`, `Tenancy\OrganizationScope` | Los datos son de una empresa: un scope global impide ver o tocar los de otra |
| **Autoría** | `Authorship\RecordsAuthorship`, `Authorship\SoftDeletesWithAuthor` | `created_by`, `updated_by` y `deleted_by` (quién creó, editó por última vez y dio de baja) |
| **Auditoría** | `Audit\Audited`, `Audit\AuditLogger`, `Audit\AuditLog`, `Audit\AuditLogQuery` | Historial de solo agregar: quién hizo qué, con el antes y el después |

La **autoría** es una foto en cada fila (quién la tocó por última vez); la **auditoría** es el historial completo. Se complementan.

## Instalación en un servicio

`composer.json` del servicio:

```json
{
    "repositories": [
        { "type": "vcs", "url": "git@github.com:bilikui08/erp-core.git" }
    ],
    "require": {
        "gustavoluis/erp-core": "^0.1"
    }
}
```

Quien instale (tu máquina, el servidor de producción) necesita acceso SSH a ese repositorio privado.
Laravel descubre el paquete solo. Después, `php artisan migrate` crea la tabla `audit_logs` si el servicio no la tenía.

### Guard (`config/auth.php`)

```php
'guards' => [
    'api' => [
        'driver' => 'erp-jwt',
        'public_key' => env('AUTH_JWT_PUBLIC_KEY_PATH', storage_path('oauth-public.key')), // solo verifica, nunca firma
        // 'accept_legacy_tokens' => true,   // solo servicios anteriores a las empresas; un servicio nuevo no lo necesita
    ],
],
```

### Rutas

```php
Route::middleware(['auth:api', 'permission:shipping.order.view,shipping.order.manage'])->get('/orders', ...); // alcanza con uno
```

Se chequean **permisos, nunca roles**. Sin permiso responde 403 (`errors.permission` lista lo que se pedía).

### Modelos

```php
class Shipment extends Model
{
    use BelongsToOrganization;   // columna organization_id + scope global
    use RecordsAuthorship;       // created_by / updated_by
    use SoftDeletesWithAuthor;   // deleted_at / deleted_by en la misma sentencia
    use Audited;                 // alta, edición, baja y restauración quedan en audit_logs

    public function auditType(): string { return 'shipment'; }
}
```

Migraciones (las columnas viven en las tablas de cada servicio, definidas igual en todos):

```php
$table->unsignedBigInteger('organization_id');
$table->authorship();             // created_by y updated_by
$table->softDeletesWithAuthor();  // deleted_at y deleted_by
// registros inmutables (comprobantes): $table->authorship(editor: false);  // solo created_by, indexado
```

Reglas que siguen valiendo (están en el comentario de cada clase):
- **Envolver cada operación del repositorio en `DB::transaction()`** si el modelo es auditado: así no queda un cambio sin su auditoría.
- Las consultas masivas (`Model::where()->update()` / `->delete()`) no disparan eventos: no registran autoría ni auditoría.
- Hechos que ya ocurrieron fuera de tu control (por ejemplo, un comprobante que ARCA ya autorizó) se auditan con `AuditLogger::recordSafely()`, que nunca tumba la operación.
- Los nombres de las acciones (`shipment.created`, `shipment.delivered`) y qué se audita son del servicio, no del paquete.

### Consultar la auditoría

```php
AuditLogQuery::list($organizationId, ['action' => 'shipment.created', 'user' => 'ana', 'date_from' => '2026-11-01'], onlyUserId: null, perPage: 25);
```

El permiso para verla y si ve todo o solo lo suyo (`onlyUserId`) lo decide cada servicio en su endpoint.

## Versiones

Se versiona con tags (`v0.1.0`, `v0.2.0`...). Los servicios fijan `^0.1`, `^0.2`...

- Los cambios en `audit_logs` deben ser **compatibles hacia atrás**: los servicios se actualizan en momentos distintos.
- Para actualizar un servicio: `composer update gustavoluis/erp-core`, `php artisan migrate` y correr sus tests.

## Desarrollo

```
composer install
vendor/bin/phpunit
```

En Docker, con la imagen de PHP de billing: `docker run --rm -v "$PWD":/app -w /app facturin-php sh -c 'composer install && vendor/bin/phpunit'`.
