<?php

namespace Tests\Feature\Models\Notice;

use App\Models\Master\User;
use App\Models\Notice\NoticeMute;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 通知のミュートの印の表の制約(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節の起きない組み合わせ、
 * ADR 0050 の決定 1。名前は 設計/共通マスタ/共通の規則.md の 9 節の形): 利用者と種類の組の一意・種類の書式・利用者の外部キー(RESTRICT)。
 * 列と外部キーの一覧は SchemaTest・ForeignKeysTest も確かめる。
 */
final class NoticeMuteTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_a_user_has_at_most_one_row_per_type_and_may_have_rows_of_other_types(): void
    {
        $row = NoticeMute::factory()->createOne(['type' => 'workflow.process_request']);
        NoticeMute::factory()->createOne(['user_id' => $row->user_id, 'type' => 'records.comment']);
        NoticeMute::factory()->createOne(['type' => 'workflow.process_request']);

        $duplicate = ['user_id' => $row->user_id, 'type' => 'workflow.process_request'];

        $this->assertRejectedBy(
            'notice_mutes_user_id_type_unique',
            fn () => NoticeMute::factory()->createOne($duplicate),
        );
    }

    #[TestWith(['workflow'])]
    #[TestWith(['Workflow.process'])]
    #[TestWith(['workflow.process-request'])]
    #[TestWith(['workflow.process.request'])]
    public function test_rejects_a_type_outside_the_module_dot_kind_form(string $type): void
    {
        $this->assertRejectedBy(
            'notice_mutes_type_check',
            fn () => NoticeMute::factory()->createOne(['type' => $type]),
        );
    }

    public function test_a_type_outside_the_list_is_accepted_by_the_table(): void
    {
        $this->assertModelExists(NoticeMute::factory()->createOne(['type' => 'test.unlisted']));
    }

    public function test_rejects_deleting_a_user_with_a_row(): void
    {
        $row = NoticeMute::factory()->createOne();

        $this->assertRejectedBy(
            'notice_mutes_user_id_foreign',
            fn () => User::query()->whereKey($row->user_id)->delete(),
        );
    }

    public function test_the_database_has_exactly_the_designed_constraint_and_index_names(): void
    {
        $this->assertSame([
            'notice_mutes_pkey',
            'notice_mutes_type_check',
            'notice_mutes_user_id_foreign',
            'notice_mutes_user_id_type_unique',
        ], $this->constraintAndIndexNames('notice_mutes'));
    }

    /**
     * @return list<string>
     */
    private function constraintAndIndexNames(string $table): array
    {
        $constraints = DB::table('pg_constraint')->whereRaw('conrelid = ?::regclass', [$table])
            ->where('contype', '<>', 'n')->pluck('conname')->all();
        $indexes = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', $table)->pluck('indexname')->all();
        $names = array_values(array_unique([...$constraints, ...$indexes]));
        sort($names);

        return $names;
    }
}
