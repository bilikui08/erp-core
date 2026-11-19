<?php

namespace GustavoLuis\ErpCore\Tests;

use GustavoLuis\ErpCore\Auth\AuthenticatedUser;
use GustavoLuis\ErpCore\ErpCoreServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [ErpCoreServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Tablas de los modelos de ejemplo (son lo que cada servicio define en sus migraciones)
        Schema::create('widgets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->string('name');
            $table->integer('stock')->default(0);
            $table->timestamps();
            $table->authorship();
            $table->softDeletesWithAuthor();
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->decimal('amount', 10, 2);
            $table->string('created_by_name', 120)->nullable();
            $table->timestamps();
            $table->authorship(editor: false);
        });
    }

    /** Pasa a ser la persona autenticada (el guard por defecto de los tests devuelve este objeto). */
    protected function actAs(int $id, ?int $organization = 7, array $permissions = [], ?string $name = 'Ana Pérez'): AuthenticatedUser
    {
        $user = new AuthenticatedUser($id, $name, $organization, $permissions);
        $this->actingAs($user);

        return $user;
    }

    /** Vuelve a no haber nadie autenticado (consola, seeders). */
    protected function actAsNobody(): void
    {
        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse('web');
        $this->app['auth']->guard('web')->forgetUser();
    }
}
