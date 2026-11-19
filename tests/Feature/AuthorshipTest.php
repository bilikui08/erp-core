<?php

namespace GustavoLuis\ErpCore\Tests\Feature;

use GustavoLuis\ErpCore\Tests\Support\Receipt;
use GustavoLuis\ErpCore\Tests\Support\Widget;
use GustavoLuis\ErpCore\Tests\TestCase;
use Illuminate\Support\Facades\DB;

/** Quién creó (`created_by`), quién editó por última vez (`updated_by`) y quién dio de baja (`deleted_by`). Nunca se asignan a mano. */
class AuthorshipTest extends TestCase
{
    private function row(int $id): object
    {
        return DB::table('widgets')->where('id', $id)->first();
    }

    public function test_the_creator_is_recorded_and_the_editor_stays_empty_until_the_first_edit(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);

        $row = $this->row($widget->id);
        $this->assertSame(5, (int) $row->created_by);
        $this->assertNull($row->updated_by);
        $this->assertNull($row->deleted_by);
    }

    public function test_the_last_editor_is_recorded_and_the_creator_never_changes(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);

        $this->actAs(6, 7);
        Widget::findOrFail($widget->id)->update(['name' => 'B']);

        $row = $this->row($widget->id);
        $this->assertSame(5, (int) $row->created_by);
        $this->assertSame(6, (int) $row->updated_by);
    }

    public function test_saving_without_changes_does_not_claim_an_edit(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);

        $this->actAs(6, 7);
        Widget::findOrFail($widget->id)->save();

        $this->assertNull($this->row($widget->id)->updated_by);
    }

    public function test_outside_an_authenticated_request_the_columns_stay_empty(): void
    {
        $this->actAsNobody();
        $widget = Widget::create(['organization_id' => 7, 'name' => 'Seeder']);
        $widget->update(['name' => 'Seeder 2']);

        $row = $this->row($widget->id);
        $this->assertNull($row->created_by);
        $this->assertNull($row->updated_by);
    }

    public function test_the_person_who_deleted_is_recorded_in_the_same_statement_as_the_deletion(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);

        $this->actAs(8, 7);
        DB::enableQueryLog();
        Widget::findOrFail($widget->id)->delete();
        $updates = collect(DB::getQueryLog())->filter(fn ($q) => str_starts_with($q['query'], 'update "widgets"'));

        $this->assertCount(1, $updates, 'una sola sentencia: no puede quedar una baja sin autor');
        $this->assertStringContainsString('deleted_at', $updates->first()['query']);
        $this->assertStringContainsString('deleted_by', $updates->first()['query']);

        $row = $this->row($widget->id);
        $this->assertNotNull($row->deleted_at);
        $this->assertSame(8, (int) $row->deleted_by);
        $this->assertSame(5, (int) $row->created_by);
    }

    public function test_restoring_clears_who_deleted(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);
        $widget->delete();
        $this->assertSame(5, (int) $this->row($widget->id)->deleted_by);

        $this->actAs(9, 7);
        Widget::onlyTrashed()->findOrFail($widget->id)->restore();

        $row = $this->row($widget->id);
        $this->assertNull($row->deleted_at);
        $this->assertNull($row->deleted_by);
    }

    public function test_deleted_records_disappear_from_normal_queries_but_stay_available(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);
        $widget->delete();

        $this->assertSame(0, Widget::count());
        $this->assertSame(1, Widget::onlyTrashed()->count());
        $this->assertSame(1, Widget::withTrashed()->count());
    }

    public function test_an_immutable_record_keeps_only_who_created_it_and_their_name(): void
    {
        $this->actAs(5, 7, [], 'Ana Pérez');
        $receipt = Receipt::create(['amount' => 100]);

        $this->actAs(6, 7, [], 'Beto');
        $receipt = Receipt::findOrFail($receipt->id);
        $receipt->amount = 200;
        $receipt->save();

        $row = DB::table('receipts')->where('id', $receipt->id)->first();
        $this->assertSame(5, (int) $row->created_by);
        $this->assertSame('Ana Pérez', $row->created_by_name, 'foto fija: sigue diciendo quién era aunque cambie después');
        $this->assertFalse(property_exists($row, 'updated_by'), 'los inmutables no tienen columna de editor');
    }

    public function test_the_author_name_is_empty_when_the_token_has_none(): void
    {
        $this->actAs(5, 7, [], null);

        $this->assertNull(Receipt::create(['amount' => 1])->created_by_name);
    }
}
