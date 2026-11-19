<?php

namespace GustavoLuis\ErpCore\Tests\Feature;

use GustavoLuis\ErpCore\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MigrationAndMacrosTest extends TestCase
{
    private function migration(): object
    {
        return require __DIR__ . '/../../database/migrations/2026_11_21_000000_create_audit_logs_table.php';
    }

    public function test_a_service_without_an_audit_table_gets_the_standard_one(): void
    {
        $this->assertTrue(Schema::hasTable('audit_logs'), 'se crea sola al migrar');
        $this->assertTrue(Schema::hasColumns('audit_logs', [
            'id', 'organization_id', 'user_id', 'user_name', 'action', 'auditable_type', 'auditable_id', 'changes', 'ip_address', 'user_agent', 'created_at',
        ]));
        $this->assertFalse(Schema::hasColumn('audit_logs', 'updated_at'), 'es de solo agregar');
    }

    public function test_it_leaves_an_existing_audit_table_alone(): void
    {
        // billing ya tenía su tabla `audit_logs` antes de existir el paquete
        Schema::drop('audit_logs');
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('propia')->nullable();
        });
        DB::table('audit_logs')->insert(['organization_id' => 1, 'propia' => 'dato']);

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('audit_logs', 'propia'), 'no la recreó ni la tocó');
        $this->assertSame('dato', DB::table('audit_logs')->value('propia'));
    }

    public function test_running_it_twice_is_harmless_and_down_never_deletes_the_history(): void
    {
        DB::table('audit_logs')->insert(['action' => 'x.created', 'auditable_type' => 'x']);

        $this->migration()->up();
        $this->migration()->down();

        $this->assertSame(1, DB::table('audit_logs')->count());
    }

    public function test_the_authorship_shortcut_defines_the_standard_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('widgets', ['created_by', 'updated_by', 'deleted_at', 'deleted_by']));
        $this->assertSame('integer', Schema::getColumnType('widgets', 'created_by'));
        $this->assertTrue(collect(Schema::getColumns('widgets'))->firstWhere('name', 'deleted_by')['nullable']);
    }

    public function test_the_immutable_variant_has_only_the_creator_and_it_is_indexed(): void
    {
        $this->assertTrue(Schema::hasColumn('receipts', 'created_by'));
        $this->assertFalse(Schema::hasColumn('receipts', 'updated_by'));
        $this->assertTrue(collect(Schema::getIndexes('receipts'))->contains(fn ($i) => $i['columns'] === ['created_by']));
    }

    public function test_the_package_registers_its_guard_and_config(): void
    {
        config(['auth.guards.probe' => ['driver' => 'erp-jwt', 'public_key' => '/no/existe']]);

        $this->assertInstanceOf(\GustavoLuis\ErpCore\Auth\PassportJwtGuard::class, $this->app['auth']->guard('probe'));
        $this->assertFalse(config('erp-core.auth.accept_legacy_tokens'), 'un servicio nuevo no acepta tokens sin empresa');
    }
}
