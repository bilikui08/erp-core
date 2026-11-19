<?php

namespace GustavoLuis\ErpCore\Tests\Feature;

use GustavoLuis\ErpCore\Audit\AuditLog;
use GustavoLuis\ErpCore\Audit\AuditLogQuery;
use GustavoLuis\ErpCore\Tests\TestCase;

/** Consulta de la auditoría de una empresa: nunca la de otra, con filtros, de lo más nuevo a lo más viejo. */
class AuditLogQueryTest extends TestCase
{
    private function log(int $org, ?int $user, ?string $name, string $action = 'widget.created', string $type = 'widget', ?int $id = 1, string $at = '2026-11-10 10:00:00'): AuditLog
    {
        return AuditLog::create([
            'organization_id' => $org, 'user_id' => $user, 'user_name' => $name, 'action' => $action,
            'auditable_type' => $type, 'auditable_id' => $id, 'created_at' => $at,
        ]);
    }

    private function ids(array $filters = [], ?int $only = null, int $org = 1): array
    {
        return AuditLogQuery::list($org, $filters, $only)->pluck('id')->all();
    }

    public function test_a_company_never_sees_the_audit_of_another(): void
    {
        $mine = $this->log(1, 5, 'Ana');
        $this->log(2, 5, 'Ana');

        $this->assertSame([$mine->id], $this->ids());
        $this->assertSame([], $this->ids(org: 3));
    }

    public function test_newest_first(): void
    {
        $a = $this->log(1, 5, 'Ana');
        $b = $this->log(1, 5, 'Ana');

        $this->assertSame([$b->id, $a->id], $this->ids());
    }

    public function test_only_the_own_actions_of_a_person(): void
    {
        $ana = $this->log(1, 5, 'Ana');
        $this->log(1, 6, 'Beto');
        $this->log(1, null, null);

        $this->assertSame([$ana->id], $this->ids(only: 5));
    }

    public function test_the_own_actions_restriction_cannot_be_widened_by_a_filter(): void
    {
        $ana = $this->log(1, 5, 'Ana');
        $beto = $this->log(1, 6, 'Beto');

        $this->assertSame([], $this->ids(['user_id' => 6], only: 5), 'quien ve solo lo suyo no puede pedir lo de otra persona');
        $this->assertSame([], $this->ids(['user' => 'Beto'], only: 5));
        $this->assertSame([$beto->id], $this->ids(['user_id' => 6]));
        $this->assertSame([$ana->id], $this->ids(['user' => 'ana'], only: 5));
    }

    public function test_filters_by_action_type_and_record(): void
    {
        $created = $this->log(1, 5, 'Ana', 'widget.created', 'widget', 10);
        $deleted = $this->log(1, 5, 'Ana', 'widget.deleted', 'widget', 10);
        $other = $this->log(1, 5, 'Ana', 'gadget.created', 'gadget', 11);

        $this->assertSame([$created->id], $this->ids(['action' => 'widget.created']));
        $this->assertSame([$other->id], $this->ids(['auditable_type' => 'gadget']));
        $this->assertEqualsCanonicalizing([$created->id, $deleted->id], $this->ids(['auditable_type' => 'widget', 'auditable_id' => 10]));
    }

    public function test_user_text_matches_part_of_the_name_or_the_id(): void
    {
        $ana = $this->log(1, 5, 'Ana Pérez');
        $beto = $this->log(1, 12, 'Beto Gómez');
        $anon = $this->log(1, 31, null);

        $this->assertSame([$ana->id], $this->ids(['user' => 'pérez']));
        $this->assertSame([$beto->id], $this->ids(['user' => '12']));
        $this->assertSame([$anon->id], $this->ids(['user' => 'Usuario 31']), 'como se muestran los registros sin nombre');
        $this->assertSame([$ana->id], $this->ids(['user' => 'ana']));
    }

    public function test_user_text_wildcards_are_taken_literally(): void
    {
        $this->log(1, 5, 'Ana Pérez');

        $this->assertSame([], $this->ids(['user' => '%']));
        $this->assertSame([], $this->ids(['user' => '_']));
    }

    public function test_date_range_includes_the_whole_last_day(): void
    {
        $early = $this->log(1, 5, 'Ana', at: '2026-11-10 00:00:00');
        $late = $this->log(1, 5, 'Ana', at: '2026-11-10 23:59:59');
        $next = $this->log(1, 5, 'Ana', at: '2026-11-11 00:00:00');
        $before = $this->log(1, 5, 'Ana', at: '2026-11-09 23:59:59');

        $this->assertEqualsCanonicalizing([$early->id, $late->id], $this->ids(['date_from' => '2026-11-10', 'date_to' => '2026-11-10']));
        $this->assertEqualsCanonicalizing([$next->id], $this->ids(['date_from' => '2026-11-11']));
        $this->assertEqualsCanonicalizing([$before->id], $this->ids(['date_to' => '2026-11-09']));
    }

    public function test_pagination(): void
    {
        foreach (range(1, 7) as $i) {
            $this->log(1, 5, 'Ana');
        }

        $page = AuditLogQuery::list(1, [], null, 3);

        $this->assertSame(7, $page->total());
        $this->assertCount(3, $page->items());
        $this->assertSame(3, $page->lastPage());
    }
}
