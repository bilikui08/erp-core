<?php

namespace GustavoLuis\ErpCore;

use GustavoLuis\ErpCore\Auth\PassportJwtGuard;
use GustavoLuis\ErpCore\Http\Middleware\RequirePermission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

/**
 * Se registra solo (descubrimiento de paquetes de Laravel). Aporta:
 * - el guard `erp-jwt` (para `config/auth.php`): valida los tokens de auth-api con la clave pública;
 * - el middleware `permission:` para las rutas;
 * - la migración de `audit_logs` (se crea solo si falta);
 * - los atajos `authorship()` y `softDeletesWithAuthor()` para las migraciones de cada servicio.
 */
final class ErpCoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/erp-core.php', 'erp-core');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $this->publishes([__DIR__ . '/../config/erp-core.php' => config_path('erp-core.php')], 'erp-core-config');

        $this->app['auth']->extend('erp-jwt', fn ($app, string $name, array $config) => new PassportJwtGuard(
            $app['request'],
            $config['public_key'] ?? config('erp-core.auth.public_key'),
            (bool) ($config['accept_legacy_tokens'] ?? config('erp-core.auth.accept_legacy_tokens')),
        ));

        $this->app['router']->aliasMiddleware('permission', RequirePermission::class);

        $this->registerBlueprintMacros();
    }

    /**
     * Las columnas de autoría se definen igual en todos los servicios (ids de auth-api, sin clave foránea):
     *   $table->authorship();            // created_by y updated_by
     *   $table->authorship(editor: false); // solo created_by, indexado (registros inmutables, como los comprobantes)
     *   $table->softDeletesWithAuthor(); // deleted_at y deleted_by
     */
    private function registerBlueprintMacros(): void
    {
        Blueprint::macro('authorship', function (bool $editor = true): void {
            /** @var Blueprint $this */
            if ($editor) {
                $this->unsignedBigInteger('created_by')->nullable();
                $this->unsignedBigInteger('updated_by')->nullable();
            } else {
                $this->unsignedBigInteger('created_by')->nullable()->index();
            }
        });

        Blueprint::macro('softDeletesWithAuthor', function (): void {
            /** @var Blueprint $this */
            $this->softDeletes();
            $this->unsignedBigInteger('deleted_by')->nullable();
        });
    }
}
