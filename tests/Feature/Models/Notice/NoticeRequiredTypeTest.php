<?php

namespace Tests\Feature\Models\Notice;

use App\Models\Notice\NoticeRequiredType;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の必須の印の表の制約(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節の起きない組み合わせ、
 * ADR 0050 の決定 1。名前は 設計/共通マスタ/共通の規則.md の 9 節の形): 種類の一意・種類の書式。列の一覧は SchemaTest も確かめる。
 */
final class NoticeRequiredTypeTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_a_type_has_at_most_one_row(): void
    {
        NoticeRequiredType::factory()->createOne(['type' => 'workflow.process_request']);
        NoticeRequiredType::factory()->createOne(['type' => 'records.comment']);

        $this->assertRejectedBy(
            'notice_required_types_type_unique',
            fn () => NoticeRequiredType::factory()->createOne(['type' => 'workflow.process_request']),
        );
    }

    #[TestWith(['workflow'])]
    #[TestWith(['Workflow.process'])]
    #[TestWith(['workflow.process-request'])]
    #[TestWith(['workflow.process.request'])]
    public function test_rejects_a_type_outside_the_module_dot_kind_form(string $type): void
    {
        $this->assertRejectedBy(
            'notice_required_types_type_check',
            fn () => NoticeRequiredType::factory()->createOne(['type' => $type]),
        );
    }

    public function test_the_database_has_exactly_the_designed_constraint_and_index_names(): void
    {
        $constraints = DB::table('pg_constraint')->whereRaw("conrelid = 'notice_required_types'::regclass")
            ->where('contype', '<>', 'n')->pluck('conname')->all();
        $indexes = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', 'notice_required_types')->pluck('indexname')->all();
        $names = array_values(array_unique([...$constraints, ...$indexes]));
        sort($names);

        $this->assertSame([
            'notice_required_types_pkey',
            'notice_required_types_type_check',
            'notice_required_types_type_unique',
        ], $names);
    }
}
