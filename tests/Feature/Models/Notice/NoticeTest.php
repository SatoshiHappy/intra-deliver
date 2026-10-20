<?php

namespace Tests\Feature\Models\Notice;

use App\Models\Notice\Notice;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 通知の表の検査制約と、制約と索引の名前(docs の 設計/ワークフロー/案件の運用/通知.md の 2 節。名前は 設計/共通マスタ/共通の規則.md の 9 節の形)。
 * 列と外部キーは SchemaTest・ForeignKeysTest が確かめる。
 */
final class NoticeTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    public function test_accepts_a_notice_with_a_target_an_actor_and_empty_params(): void
    {
        $notice = Notice::factory()->withActor()->withTarget('workflow-case', 12)
            ->createOne(['type' => 'workflow.process_request']);

        $this->assertModelExists($notice);
        $this->assertSame('{}', DB::table('notices')->where('id', $notice->id)->value('params'));
    }

    #[TestWith(['workflow'])]
    #[TestWith(['Workflow.process'])]
    #[TestWith(['workflow.process-request'])]
    #[TestWith(['workflow.process.request'])]
    #[TestWith(['.process'])]
    public function test_rejects_a_type_outside_the_module_dot_kind_form(string $type): void
    {
        $this->assertRejectedBy('notices_type_check', fn () => Notice::factory()->createOne(['type' => $type]));
    }

    #[TestWith(['Workflow-case'])]
    #[TestWith(['workflow_case'])]
    #[TestWith(['-case'])]
    public function test_rejects_a_target_type_outside_its_form(string $targetType): void
    {
        $this->assertRejectedBy(
            'notices_target_type_check',
            fn () => Notice::factory()->withTarget($targetType)->createOne(),
        );
    }

    public function test_rejects_a_target_type_without_a_target_id_and_the_reverse(): void
    {
        $constraint = 'notices_target_type_target_id_check';

        $this->assertRejectedBy($constraint, fn () => Notice::factory()->createOne(['target_type' => 'workflow-case']));
        $this->assertRejectedBy($constraint, fn () => Notice::factory()->createOne(['target_id' => 1]));
    }

    public function test_rejects_params_that_are_not_a_json_object(): void
    {
        $this->assertRejectedBy(
            'notices_params_check',
            fn () => Notice::factory()->createOne(['params' => ['a', 'b']]),
        );
    }

    public function test_the_database_has_exactly_the_designed_constraint_and_index_names(): void
    {
        $constraints = DB::table('pg_constraint')->whereRaw("conrelid = 'notices'::regclass")
            ->where('contype', '<>', 'n')->pluck('conname')->all();
        $indexes = DB::table('pg_indexes')->where('schemaname', DB::raw('current_schema()'))
            ->where('tablename', 'notices')->pluck('indexname')->all();
        $names = array_values(array_unique([...$constraints, ...$indexes]));
        sort($names);

        $this->assertSame([
            'notices_actor_user_id_foreign',
            'notices_actor_user_id_index',
            'notices_params_check',
            'notices_pkey',
            'notices_target_type_check',
            'notices_target_type_target_id_check',
            'notices_type_check',
            'notices_user_id_created_at_id_index',
            'notices_user_id_foreign',
            'notices_user_id_index',
        ], $names);
    }

    public function test_the_unread_index_is_partial_on_unread_rows(): void
    {
        $definition = DB::table('pg_indexes')->where('indexname', 'notices_user_id_index')->value('indexdef');

        $this->assertStringEndsWith('(user_id) WHERE (read_at IS NULL)', (string) $definition);
    }
}
