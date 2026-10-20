<?php

namespace Tests\Feature\Models\Records;

use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\RecordRevision;
use Illuminate\Support\Facades\DB;
use Tests\Support\TransactionalTestCase;

/**
 * 更新履歴の版の操作の列挙と検査制約の一致(docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1 節。
 * 値の正本は列挙 RecordRevisionAction で、record_revisions_action_check と同じ値を持つ。一覧の外の値を拒むことは RecordRevisionTest)。
 * 書き方は Workflow/WorkflowEnumValuesTest と同じ。
 */
final class RecordRevisionActionTest extends TransactionalTestCase
{
    public function test_the_check_constraint_lists_exactly_the_enum_values(): void
    {
        $definition = DB::scalar(
            'select pg_get_constraintdef(oid) from pg_constraint where conname = ?',
            ['record_revisions_action_check'],
        );
        preg_match_all("/'([^']*)'::character varying/", (string) $definition, $matches);
        $expected = array_map(fn (RecordRevisionAction $case): string => $case->value, RecordRevisionAction::cases());
        $actual = $matches[1];
        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_accepts_every_enum_value_and_reads_it_back_as_the_enum(): void
    {
        foreach (RecordRevisionAction::cases() as $action) {
            $revision = RecordRevision::factory()->create(['action' => $action]);

            $this->assertSame($action, $revision->refresh()->action);
        }
    }
}
