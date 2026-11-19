<?php

namespace GustavoLuis\ErpCore\Tests\Feature;

use GustavoLuis\ErpCore\Tenancy\OrganizationScope;
use GustavoLuis\ErpCore\Tests\Support\Widget;
use GustavoLuis\ErpCore\Tests\TestCase;
use LogicException;

/** Los datos son de una empresa: dentro de un pedido autenticado no se ven ni se tocan los de otra, aunque la consulta no filtre. */
class TenancyTest extends TestCase
{
    private function seedTwoCompanies(): void
    {
        $this->actAsNobody();
        Widget::create(['organization_id' => 1, 'name' => 'De la empresa 1']);
        Widget::create(['organization_id' => 2, 'name' => 'De la empresa 2']);
    }

    public function test_queries_only_see_the_active_company(): void
    {
        $this->seedTwoCompanies();
        $this->actAs(10, 1);

        $this->assertSame(['De la empresa 1'], Widget::pluck('name')->all());
        $this->assertSame(1, Widget::count());
        $this->assertNull(Widget::find(2), 'ni siquiera por id');
    }

    public function test_without_a_person_nothing_is_filtered(): void
    {
        $this->seedTwoCompanies();
        $this->actAsNobody();

        $this->assertSame(2, Widget::count(), 'consola y seeders ven todo: ahí se acota a mano');
        $this->assertSame(1, Widget::withoutGlobalScope(OrganizationScope::class)->where('organization_id', 2)->count());
    }

    public function test_a_new_record_takes_the_active_company(): void
    {
        $this->actAs(10, 3);

        $this->assertSame(3, Widget::create(['name' => 'Nuevo'])->organization_id);
    }

    public function test_it_is_impossible_to_create_a_record_for_another_company(): void
    {
        $this->actAs(10, 1);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('No se puede crear ni modificar un registro de otra empresa.');

        Widget::create(['organization_id' => 2, 'name' => 'Ajeno']);
    }

    public function test_it_is_impossible_to_move_or_edit_a_record_of_another_company(): void
    {
        $this->seedTwoCompanies();
        $this->actAs(10, 1);
        $own = Widget::firstOrFail();

        $own->organization_id = 2;
        try {
            $own->save();
            $this->fail('movió un registro a otra empresa');
        } catch (LogicException) {
            $this->assertSame(1, (int) Widget::withoutGlobalScopes()->find($own->id)->organization_id);
        }

        $foreign = Widget::withoutGlobalScopes()->where('organization_id', 2)->firstOrFail();
        $foreign->name = 'Hackeado';

        $this->expectException(LogicException::class);
        $foreign->save();
    }

    public function test_a_person_from_before_the_companies_works_in_their_own(): void
    {
        $this->actAs(42, null);

        $this->assertSame(42, Widget::create(['name' => 'Legado'])->organization_id);
    }

    public function test_every_query_goes_through_the_scope_even_with_joins_and_counts(): void
    {
        $this->seedTwoCompanies();
        $this->actAs(10, 2);

        $this->assertSame(1, Widget::query()->count());
        $this->assertSame(1, Widget::query()->where('name', 'like', 'De la%')->get()->count());
        $this->assertStringContainsString('"widgets"."organization_id" = ?', Widget::query()->toSql());
    }
}
