<?php

namespace GustavoLuis\ErpCore\Tests\Feature;

use GustavoLuis\ErpCore\Audit\AuditLog;
use GustavoLuis\ErpCore\Audit\AuditLogger;
use GustavoLuis\ErpCore\Tests\Support\Widget;
use GustavoLuis\ErpCore\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use LogicException;

/** Registro de auditoría: alta, edición (antes y después), baja, restauración; solo se agrega y nunca queda un cambio sin su entrada. */
class AuditTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        Route::post('/widgets', fn () => response()->json(['id' => Widget::create(['name' => 'Por HTTP'])->id]));
    }

    private function actions(): array
    {
        return AuditLog::orderBy('id')->pluck('action')->all();
    }

    public function test_creating_records_the_values_without_the_bookkeeping_columns(): void
    {
        $this->actAs(5, 7, [], 'Ana Pérez');
        $widget = Widget::create(['name' => 'A', 'stock' => 3]);

        $log = AuditLog::sole();
        $this->assertSame('widget.created', $log->action);
        $this->assertSame('widget', $log->auditable_type);
        $this->assertSame($widget->id, (int) $log->auditable_id);
        $this->assertSame(7, (int) $log->organization_id);
        $this->assertSame(5, (int) $log->user_id);
        $this->assertSame('Ana Pérez', $log->user_name);
        $this->assertSame('A', $log->changes['values']['name']);
        $this->assertSame(3, $log->changes['values']['stock']);
        foreach (['id', 'created_at', 'updated_at', 'deleted_at', 'created_by', 'updated_by', 'deleted_by'] as $ignored) {
            $this->assertArrayNotHasKey($ignored, $log->changes['values'], $ignored);
        }
    }

    public function test_editing_records_before_and_after_of_what_changed_only(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A', 'stock' => 3]);
        $widget->update(['name' => 'B']);

        $log = AuditLog::where('action', 'widget.updated')->sole();
        $this->assertSame(['name' => ['old' => 'A', 'new' => 'B']], $log->changes['changes']);
    }

    public function test_a_save_with_no_business_change_leaves_no_entry(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);
        $this->travel(5)->minutes();
        $widget->touch(); // solo cambia updated_at
        $widget->save();

        $this->assertSame(['widget.created'], $this->actions());
    }

    public function test_delete_and_restore_are_recorded_in_order(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);
        $widget->delete();
        Widget::onlyTrashed()->findOrFail($widget->id)->restore();

        $this->assertSame(['widget.created', 'widget.deleted', 'widget.restored'], $this->actions());
    }

    public function test_a_hard_delete_is_recorded_with_what_was_lost(): void
    {
        $this->actAs(5, 7);
        $widget = Widget::create(['name' => 'A']);
        $widget->forceDelete();

        $log = AuditLog::where('action', 'widget.purged')->sole();
        $this->assertSame('A', $log->changes['values']['name']);
    }

    public function test_the_request_origin_is_recorded(): void
    {
        $this->actAs(5, 7);

        $this->withHeader('User-Agent', 'PruebaDeAuditoria/1.0')->postJson('/widgets')->assertOk();

        $log = AuditLog::sole();
        $this->assertSame('127.0.0.1', $log->ip_address);
        $this->assertSame('PruebaDeAuditoria/1.0', $log->user_agent);
    }

    public function test_the_user_agent_is_truncated(): void
    {
        $this->actAs(5, 7);

        $this->withHeader('User-Agent', str_repeat('x', 600))->postJson('/widgets')->assertOk();

        $this->assertSame(250, strlen(AuditLog::sole()->user_agent));
    }

    public function test_console_work_is_recorded_without_a_person(): void
    {
        $this->actAsNobody();
        Widget::create(['organization_id' => 7, 'name' => 'Seeder']);

        $log = AuditLog::sole();
        $this->assertNull($log->user_id);
        $this->assertNull($log->user_name);
        $this->assertSame(7, (int) $log->organization_id);
    }

    public function test_the_audit_log_is_append_only(): void
    {
        $this->actAs(5, 7);
        Widget::create(['name' => 'A']);
        $log = AuditLog::sole();

        try {
            $log->update(['action' => 'otra.cosa']);
            $this->fail('se modificó un registro de auditoría');
        } catch (LogicException $e) {
            $this->assertStringContainsString('solo lectura', $e->getMessage());
        }

        try {
            $log->delete();
            $this->fail('se borró un registro de auditoría');
        } catch (LogicException $e) {
            $this->assertStringContainsString('solo lectura', $e->getMessage());
        }

        $this->assertSame('widget.created', AuditLog::sole()->action);
    }

    public function test_a_change_cannot_survive_without_its_audit_entry(): void
    {
        $this->actAs(5, 7);
        Schema::drop('audit_logs'); // la auditoría va a fallar

        try {
            DB::transaction(fn () => Widget::create(['name' => 'Sin auditoría']));
            $this->fail('el cambio se confirmó sin su auditoría');
        } catch (\Throwable) {
            $this->assertSame(0, DB::table('widgets')->count(), 'el alta se deshizo con la transacción');
        }
    }

    public function test_recordSafely_never_takes_down_an_operation_that_already_happened(): void
    {
        $this->actAsNobody();
        $widget = Widget::withoutEvents(fn () => Widget::create(['organization_id' => 7, 'name' => 'A']));
        Schema::drop('audit_logs');
        Log::spy();

        AuditLogger::recordSafely($widget, 'widget.issued', ['values' => ['x' => 1]]); // no lanza

        Log::shouldHaveReceived('critical')->once()->withArgs(fn (string $message) => str_contains($message, 'widget.issued'));
    }

    public function test_a_model_without_a_company_column_can_override_who_owns_the_audit(): void
    {
        $this->actAs(5, 7);
        $widget = new class extends Widget {
            protected $table = 'widgets';

            public function auditOrganizationId(): ?int
            {
                return 99;
            }
        };
        $widget->forceFill(['organization_id' => 7, 'name' => 'Raro'])->save();

        $this->assertSame(99, (int) AuditLog::sole()->organization_id);
    }
}
