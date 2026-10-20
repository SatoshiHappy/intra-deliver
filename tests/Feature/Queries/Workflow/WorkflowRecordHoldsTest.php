<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Records\Api\RecordHold;
use App\Queries\Workflow\WorkflowRecordHolds;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordModules;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの持ち主のワークフローの実装(docs の 設計/ワークフロー/業務データとの接続/README.md 4 節・9 節の「ワークフローの分」の持ち主の行、
 * データモデル/直列化と検査.md 6 節の「ロックの順」の (3)): 未完了の案件が指すレコードだけに持ち主(鍵 workflow-case:<案件の id>)があり、鍵を取らずに読む。
 * 起動の登録を通した本物の実装で、業務データの入れ物の更新・削除が拒まれ、完了の後は更新が通り削除は参照されているので拒まれる。
 */
final class WorkflowRecordHoldsTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use CatchesValidationErrors;

    private const REASON = '申請中のため変更できません';

    private const REFERENCED = 'このレコードはほかのデータから参照されているため削除できません';

    public function test_an_active_case_holds_its_record(): void
    {
        $module = $this->recordModule('wf_holds');
        $held = $this->record($module);
        $free = $this->record($module);
        $case = $this->caseOf($module, $held);

        $this->assertEquals([$held->id => new RecordHold("workflow-case:{$case->id}", self::REASON)], $this->holds()->holdsOf([$held->id, $free->id]));
        $this->assertSame([], $this->holds()->holdsOf([]));
    }

    public function test_completed_and_draft_cases_hold_nothing(): void
    {
        $module = $this->recordModule('wf_holds');
        $record = $this->record($module);
        $this->caseOf($module, $record, completed: true);
        WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $this->flowOf($module)->id]);

        $this->assertSame([], $this->holds()->holdsOf([$record->id]));
        $this->assertNull($this->holds()->firstHoldInModule($module->id));
    }

    public function test_the_first_hold_in_a_module_is_its_active_case_with_the_smallest_id(): void
    {
        $module = $this->recordModule('wf_holds');
        $other = $this->recordModule('wf_others');
        $this->caseOf($module, $this->record($module), completed: true);
        $first = $this->caseOf($module, $this->record($module));
        $this->caseOf($module, $this->record($module));

        $this->assertEquals(new RecordHold("workflow-case:{$first->id}", self::REASON), $this->holds()->firstHoldInModule($module->id));
        $this->assertNull($this->holds()->firstHoldInModule($other->id));
    }

    /**
     * 持ち主の読み取りは 1 回の問い合わせで、FOR UPDATE・FOR SHARE を出さない。
     */
    public function test_reads_the_cases_once_without_locks(): void
    {
        $module = $this->recordModule('wf_holds');
        $record = $this->record($module);
        $this->caseOf($module, $record);

        $holdsOf = $this->queries(fn () => $this->holds()->holdsOf([$record->id]));
        $firstHold = $this->queries(fn () => $this->holds()->firstHoldInModule($module->id));

        $this->assertCount(1, $holdsOf);
        $this->assertCount(1, $firstHold);

        foreach ([...$holdsOf, ...$firstHold] as $sql) {
            $this->assertStringNotContainsString('for update', strtolower($sql));
            $this->assertStringNotContainsString('for share', strtolower($sql));
        }
    }

    /**
     * 起動の登録を通した本物の持ち主で、未完了の案件のレコードは業務データの入れ物の更新(鍵なし)・削除が 422 record で何も変わらない。
     * 完了の後は更新が通り、削除は案件の外部キーで 422 record(参照されている)。
     */
    public function test_the_registered_holder_blocks_record_writes_until_the_case_completes(): void
    {
        $module = $this->recordModule('wf_holds');
        $title = $this->field($module, 'title');
        $record = $this->record($module, [$title->id => '旧']);
        $case = $this->caseOf($module, $record);

        $this->assertSame(['record' => self::REASON], $this->validationErrors(fn () => $this->updateRecord($record, '新')));
        $this->assertSame(['record' => self::REASON], $this->validationErrors(fn () => $this->deleteRecord($record)));
        $this->assertEquals([$title->id => '旧'], $record->refresh()->field_values);

        $case->update(['state' => WorkflowCaseState::Completed, 'completion_reason' => WorkflowCompletionReason::Approved, 'completed_at' => now()]);
        $this->updateRecord($record, '新');

        $this->assertEquals([$title->id => '新'], $record->refresh()->field_values);
        $this->assertSame(['record' => self::REFERENCED], $this->validationErrors(fn () => $this->deleteRecord($record)));
        $this->assertModelExists($record);
    }

    private function holds(): WorkflowRecordHolds
    {
        return app(WorkflowRecordHolds::class);
    }

    /**
     * @param  array<int, mixed>  $fieldValues
     */
    private function record(RecordModule $module, array $fieldValues = []): Record
    {
        return Record::factory()->create(['record_module_id' => $module->id, 'field_values' => $fieldValues]);
    }

    private function flowOf(RecordModule $module): WorkflowFlow
    {
        return WorkflowFlow::factory()->create(['workflow_content_id' => WorkflowContent::query()->firstOrCreate(['record_module_id' => $module->id], ['code' => "content_{$module->code}", 'name' => $module->code])->id]);
    }

    /**
     * レコードを指す未完了(completed なら完了)の案件。
     */
    private function caseOf(RecordModule $module, Record $record, bool $completed = false): WorkflowCase
    {
        $factory = $completed ? WorkflowCase::factory()->completed() : WorkflowCase::factory()->active();

        return $factory->create(['workflow_flow_id' => $this->flowOf($module)->id, 'record_id' => $record->id]);
    }

    private function updateRecord(Record $record, string $title): void
    {
        app(UpdateRecord::class)->execute($this->contextFor(), 'wf_holds', $record->id, new RecordInput(['title' => $title]), $record->refresh()->version());
    }

    private function deleteRecord(Record $record): void
    {
        app(DeleteRecord::class)->execute($this->contextFor(), 'wf_holds', $record->id);
    }

    /**
     * 処理の間に発行した SQL。
     *
     * @param  Closure(): mixed  $action
     * @return list<string>
     */
    private function queries(Closure $action): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $action();

        return array_values(array_map(fn (array $query) => (string) $query['query'], DB::getQueryLog()));
    }
}
