<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro de auditoría de solo agregar: quién hizo qué, cuándo y sobre qué registro.
 *
 * - `organization_id`: empresa dueña de los datos (id de auth-api); con él cada empresa consulta solo su auditoría.
 * - `user_id` / `user_name`: quién ejecutó la acción (el `sub` y el `name` del token; el nombre es una foto fija).
 *   Vacíos en tareas de consola.
 * - Sin claves foráneas: los servicios no tienen tabla de usuarios, y el registro debe sobrevivir a cualquier baja.
 * - La aplicación nunca actualiza ni borra filas de esta tabla (ver AuditLog).
 *
 * Es idempotente: si el servicio ya tenía su propia tabla `audit_logs` (billing la creó antes de existir este paquete, con
 * este mismo esquema final), no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('audit_logs')) {
            return;
        }

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name', 120)->nullable();
            $table->string('action', 60)->index();
            $table->string('auditable_type', 40);
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'created_at']);
            $table->index(['auditable_type', 'auditable_id']);
        });
    }

    public function down(): void
    {
        // No se borra: es el historial. Si hace falta, se elimina a mano.
    }
};
