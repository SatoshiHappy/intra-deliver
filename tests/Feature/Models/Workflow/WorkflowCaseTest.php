<?php

namespace Tests\Feature\Models\Workflow;

use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\Department;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlowVersion;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の制約(docs の 設計/ワークフロー/データモデル/表.md 2.5、直列化と検査.md 6 節の「案件の状態と列の組」「未完了の案件と案件番号」「シーケンス」):
 * 状態ごとに持つ列の組、一時保存の入力が JSON のオブジェクト、改訂番号が負でない、案件名が空でない、同じレコードに未完了の案件は 1 つまで、
 * 案件番号の一意、案件番号のシーケンス。段 3a(ルートの深さ/データモデル/案件の表.md 3.1、直列化と検査.md 7 節の「案件」): 一時保存だけが写した版と申請基準日を
 * 持たない、申請基準日は特殊値でない、写した版は案件のフローの版。
 */
final class WorkflowCaseTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const RECORD_CASE_NUMBER_APPLIED_AT = 'workflow_cases_state_record_id_case_number_applied_at_check';

    private const NAME_DRAFT_INPUT = 'workflow_cases_state_name_draft_input_check';

    private const COMPLETION = 'workflow_cases_state_completion_reason_completed_at_check';

    private const VERSION_BASE_DATE = 'workflow_cases_state_workflow_flow_version_id_base_date_check';

    /**
     * 状態(factory の状態)・上書きする列・拒む制約。値が Closure の列は factory が書く直前に評価する。
     *
     * @return array<string, array{string, array<string, mixed>, string}>
     */
    public static function rowsAgainstTheirState(): array
    {
        return [
            '一時保存がレコードを持つ' => ['draft', ['record_id' => fn (): int => Record::factory()->create()->id], self::RECORD_CASE_NUMBER_APPLIED_AT],
            '一時保存が案件番号を持つ' => ['draft', ['case_number' => 1], self::RECORD_CASE_NUMBER_APPLIED_AT],
            '一時保存が申請日時を持つ' => ['draft', ['applied_at' => '2026-10-13 09:00:00'], self::RECORD_CASE_NUMBER_APPLIED_AT],
            '一時保存が案件名を持つ' => ['draft', ['name' => '交通費の精算'], self::NAME_DRAFT_INPUT],
            '一時保存の入力の無い一時保存' => ['draft', ['draft_input' => null], self::NAME_DRAFT_INPUT],
            '一時保存の入力を持つ未完了' => ['active', ['draft_input' => ['name' => null]], self::NAME_DRAFT_INPUT],
            '完了の理由の無い完了' => ['completed', ['completion_reason' => null], self::COMPLETION],
            '完了の理由を持つ未完了' => ['active', ['completion_reason' => WorkflowCompletionReason::Approved], self::COMPLETION],
            '完了日時の無い完了' => ['completed', ['completed_at' => null], self::COMPLETION],
            '完了日時を持つ未完了' => ['active', ['completed_at' => '2026-10-13 09:00:00'], self::COMPLETION],
            '担当組織を持つ一時保存' => ['draft', ['department_id' => fn (): int => Department::factory()->create()->id], 'workflow_cases_state_department_id_check'],
            '一時保存の入力が配列' => ['draft', ['draft_input' => ['a', 'b']], 'workflow_cases_draft_input_check'],
            '改訂番号が負' => ['active', ['revision' => -1], 'workflow_cases_revision_check'],
            '案件名が空' => ['active', ['name' => ''], 'workflow_cases_name_check'],
            '一時保存が申請基準日を持つ' => ['draft', ['base_date' => '2026-10-13'], self::VERSION_BASE_DATE],
            '未完了が版を持たない' => ['active', ['workflow_flow_version_id' => null], self::VERSION_BASE_DATE],
            '未完了が申請基準日を持たない' => ['active', ['base_date' => null], self::VERSION_BASE_DATE],
            '完了が版を持たない' => ['completed', ['workflow_flow_version_id' => null], self::VERSION_BASE_DATE],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('rowsAgainstTheirState')]
    public function test_rejects_a_row_whose_columns_do_not_match_its_state(string $state, array $overrides, string $constraint): void
    {
        $this->assertRejectedBy($constraint, fn () => WorkflowCase::factory()->{$state}()->createOne(array_map(
            fn (mixed $value): mixed => $value instanceof Closure ? $value() : $value,
            $overrides,
        )));
    }

    public function test_accepts_a_row_of_each_state(): void
    {
        $cases = [WorkflowCase::factory()->draft()->create(), WorkflowCase::factory()->active()->create(), WorkflowCase::factory()->completed()->create()];

        foreach ($cases as $case) {
            $this->assertModelExists($case);
        }
    }

    public function test_rejects_an_infinite_base_date(): void
    {
        $row = [...WorkflowCase::factory()->active()->makeOne()->getAttributes(), 'base_date' => 'infinity'];

        $this->assertRejectedBy('workflow_cases_base_date_check', fn () => DB::table('workflow_cases')->insert($row));
    }

    public function test_rejects_a_draft_with_a_version(): void
    {
        $draft = WorkflowCase::factory()->draft()->create();
        $version = WorkflowFlowVersion::factory()->createOne(['workflow_flow_id' => $draft->workflow_flow_id]);

        $this->assertRejectedBy(self::VERSION_BASE_DATE, fn () => $draft->update(['workflow_flow_version_id' => $version->id]));
    }

    public function test_rejects_a_version_of_another_flow(): void
    {
        $active = WorkflowCase::factory()->active()->create();
        $elsewhere = WorkflowFlowVersion::factory()->createOne();

        $this->assertRejectedBy('workflow_cases_workflow_flow_id_workflow_flow_version_id_foreign', fn () => $active->update(['workflow_flow_version_id' => $elsewhere->id]));
    }

    /**
     * factory の未完了の案件は、申請基準日に有効な版を写した版にし、その日に有効な版が無ければ作らない(申請に使う版の規則に反するデータでテストが緑にならないように)。
     */
    public function test_the_factory_copies_the_version_of_the_base_date(): void
    {
        $old = WorkflowFlowVersion::factory()->createOne(['start_date' => '2025-04-01', 'end_date' => '2026-04-01']);
        $current = WorkflowFlowVersion::factory()->createOne(['workflow_flow_id' => $old->workflow_flow_id, 'start_date' => '2026-04-01']);

        $cases = [
            WorkflowCase::factory()->active()->createOne(['workflow_flow_id' => $old->workflow_flow_id, 'base_date' => '2025-10-01']),
            WorkflowCase::factory()->active()->createOne(['workflow_flow_id' => $old->workflow_flow_id, 'base_date' => '2026-10-13']),
        ];

        $this->assertSame([$old->id, $current->id], array_map(fn (WorkflowCase $case): ?int => $case->workflow_flow_version_id, $cases));
        $this->assertThrows(fn () => WorkflowCase::factory()->active()->createOne(['workflow_flow_id' => $old->workflow_flow_id, 'base_date' => '2025-03-31']), LogicException::class);
    }

    public function test_rejects_a_second_active_case_of_the_same_record(): void
    {
        $active = WorkflowCase::factory()->active()->create();

        $this->assertRejectedBy('workflow_cases_record_id_unique', fn () => WorkflowCase::factory()->active()->create(['record_id' => $active->record_id]));
    }

    public function test_accepts_a_completed_case_and_an_active_case_of_the_same_record(): void
    {
        $completed = WorkflowCase::factory()->completed()->create();

        $active = WorkflowCase::factory()->active()->create(['record_id' => $completed->record_id]);

        $this->assertSame($completed->record_id, $active->refresh()->record_id);
    }

    public function test_rejects_a_duplicate_case_number(): void
    {
        $existing = WorkflowCase::factory()->active()->create();

        $this->assertRejectedBy('workflow_cases_case_number_unique', fn () => WorkflowCase::factory()->active()->create(['case_number' => $existing->case_number]));
    }

    public function test_the_case_number_has_no_default_and_a_draft_has_none(): void
    {
        $default = DB::scalar("select column_default from information_schema.columns where table_schema = current_schema() and table_name = 'workflow_cases' and column_name = 'case_number'");

        $draft = WorkflowCase::factory()->draft()->create();

        $this->assertNull($default);
        $this->assertNull($draft->refresh()->case_number);
    }

    public function test_the_sequence_gives_a_number_above_the_existing_ones_and_increases(): void
    {
        WorkflowCase::factory()->active()->count(2)->create();
        $max = WorkflowCase::query()->max('case_number');

        $first = DB::scalar("select nextval('workflow_case_number_seq')");
        $second = DB::scalar("select nextval('workflow_case_number_seq')");

        $this->assertGreaterThan($max, $first);
        $this->assertGreaterThan($first, $second);
    }
}
