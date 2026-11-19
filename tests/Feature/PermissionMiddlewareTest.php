<?php

namespace GustavoLuis\ErpCore\Tests\Feature;

use GustavoLuis\ErpCore\Tests\TestCase;
use Illuminate\Support\Facades\Route;

/** `permission:a,b` en una ruta: alcanza con uno; se chequean permisos (nunca roles) y responde 403, no 401. */
class PermissionMiddlewareTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::middleware('permission:sales.invoice.view,sales.invoice.issue')->get('/guarded', fn () => response()->json(['ok' => true]));
        Route::middleware('permission:sales.invoice.annul')->get('/strict', fn () => response()->json(['ok' => true]));
    }

    public function test_one_of_the_listed_permissions_is_enough(): void
    {
        $this->actAs(1, 7, ['sales.invoice.issue']);

        $this->getJson('/guarded')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_without_any_of_them_it_is_forbidden_with_the_standard_error_shape(): void
    {
        $this->actAs(1, 7, ['sales.customer.view']);

        $this->getJson('/guarded')->assertStatus(403)->assertExactJson([
            'success' => false,
            'message' => 'No tenés permiso para realizar esta acción.',
            'code' => 'FORBIDDEN',
            'trace' => null,
            'errors' => ['permission' => ['sales.invoice.view', 'sales.invoice.issue']],
        ]);
    }

    public function test_without_a_person_it_is_forbidden_too(): void
    {
        $this->getJson('/strict')->assertStatus(403)->assertJsonPath('code', 'FORBIDDEN');
    }

    public function test_a_token_from_before_the_companies_keeps_every_permission(): void
    {
        $this->actAs(1, null, []);

        $this->getJson('/strict')->assertOk();
    }

    public function test_permissions_are_exact_not_prefixes(): void
    {
        $this->actAs(1, 7, ['sales.invoice.annul.extra', 'sales.invoice']);

        $this->getJson('/strict')->assertStatus(403);
    }

    public function test_the_alias_is_registered_by_the_package(): void
    {
        $this->assertArrayHasKey('permission', $this->app['router']->getMiddleware());
    }
}
