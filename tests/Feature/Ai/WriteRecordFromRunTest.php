<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\Api\CompletedRun;
use App\Actions\Ai\Api\FailedRun;
use App\Actions\Ai\Api\RunCompletionFailed;
use App\Actions\Ai\Api\WriteRecordFromRun;
use App\Actions\Ai\CompletingRun;
use App\Actions\Ai\ExecutePrompt;
use App\Actions\Ai\PromptAgent;
use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Ai\AiRunErrorCode;
use App\Enums\Ai\AiRunStatus;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordModuleAction;
use App\Models\Ai\AiRun;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordRevision;
use App\Queries\Records\Api\RecordDisplayQuery;
use InvalidArgumentException;
use LogicException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * AI の書き込みの口(docs の 設計/AI/公開の口.md 3.2、データと失敗と検査.md 8 節、版の契機 =
 * 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 1 節)。
 */
final class WriteRecordFromRunTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use UsesTestPromptKinds;

    private const MODULE = 'ai_notes';

    private const OPERATION = 'AI(要約の作成)';

    /** @var list<bool> 書き込みの口の結果 */
    private array $written = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerTestPromptKinds();
        $this->recordingKind->writesTo = self::MODULE;
        $this->recordingKind->onComplete = $this->writeSummary(...);
    }

    public function test_writes_only_the_fields_of_the_input_over_a_value_changed_after_the_request(): void
    {
        $record = $this->noteRecord();
        $runId = $this->queuedRun(recordId: $record->id);
        $edited = new RecordInput(['summary' => '人が直した', 'memo' => 'メモ']);
        app(UpdateRecord::class)->execute($this->contextFor(), self::MODULE, $record->id, $edited, $record->version());
        PromptAgent::fake([['summary' => 'AI の要約']]);

        app(ExecutePrompt::class)->execute($runId);

        $run = AiRun::query()->findOrFail($runId);
        $this->assertSame([true], $this->written);
        $this->assertEquals(['summary' => 'AI の要約', 'memo' => 'メモ'], $this->values($record));
        $this->assertSame([AiRunStatus::Succeeded, 0], [$run->status, $run->input_tokens]);
        $this->assertSame($this->requesterUserId(), $record->refresh()->updated_by_user_id);
    }

    /**
     * 契機のある書き込みは、その操作の時点の写しを確保するため(更新履歴.md の 1 節)。
     */
    public function test_writes_a_revision_with_the_operation_even_when_the_values_are_unchanged(): void
    {
        $record = $this->noteRecord();
        $runId = $this->queuedRun(recordId: $record->id);
        $same = new RecordInput(['summary' => 'AI の要約']);
        $context = $this->contextFor();
        app(UpdateRecord::class)->execute($context, self::MODULE, $record->id, $same, $record->version());
        PromptAgent::fake([['summary' => 'AI の要約']]);

        app(ExecutePrompt::class)->execute($runId);

        $revisions = $this->revisions($record->id);
        $this->assertSame(
            [[1, null, null, null, null], [2, self::OPERATION, null, $this->requesterUserId(), null]],
            array_map($this->revisionRow(...), $revisions),
        );
        $this->assertSame($revisions[0]->snapshot, $revisions[1]->snapshot);
    }

    public function test_an_exception_after_the_write_in_complete_leaves_no_write(): void
    {
        $record = $this->noteRecord();
        $runId = $this->queuedRun(recordId: $record->id);
        $this->recordingKind->onComplete = function (CompletedRun $run): void {
            $this->writeSummary($run);

            throw new RunCompletionFailed('output_invalid', '出力の形が違う');
        };
        PromptAgent::fake([['summary' => 'AI の要約']]);

        $run = $this->executeFailing($runId);

        $this->assertSame([true], $this->written);
        $this->assertSame(['summary' => '元の要約'], $this->values($record));
        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion], [$run->status, $run->error_code]);
        $this->assertSame('output_invalid', $this->recordingKind->failed[0]->reason);
    }

    public function test_a_deleted_target_record_is_not_written_and_the_run_succeeds(): void
    {
        $record = $this->noteRecord();
        $runId = $this->queuedRun(recordId: $record->id);
        Record::query()->whereKey($record->id)->delete();
        PromptAgent::fake([['summary' => 'AI の要約']]);

        app(ExecutePrompt::class)->execute($runId);

        $this->assertSame([false], $this->written);
        $this->assertSame(AiRunStatus::Succeeded, AiRun::query()->findOrFail($runId)->status);
    }

    public function test_the_requester_without_the_update_permission_fails_the_run_as_record_forbidden(): void
    {
        $record = $this->noteRecord([RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Delete]);
        PromptAgent::fake([['summary' => 'AI の要約']]);

        $this->assertFailedWith($this->queuedRun(recordId: $record->id), FailedRun::RECORD_FORBIDDEN);
        $this->assertSame(['summary' => '元の要約'], $this->values($record));
    }

    public function test_a_value_rejected_by_the_container_fails_the_run_as_record_rejected(): void
    {
        $record = $this->noteRecord();
        RecordField::query()->where('record_module_id', $record->record_module_id)->where('code', 'summary')
            ->update(['max_length' => 3]);
        PromptAgent::fake([['summary' => '長い AI の要約']]);

        $this->assertFailedWith($this->queuedRun(recordId: $record->id), FailedRun::RECORD_REJECTED);
        $this->assertSame(['summary' => '元の要約'], $this->values($record));
    }

    public function test_a_missing_record_read_by_the_receiver_fails_the_run_as_record_not_found(): void
    {
        $record = $this->noteRecord();
        $this->recordingKind->onComplete = function () use ($record): void {
            app(RecordDisplayQuery::class)->titleOf($record->id + 1_000_000);
        };
        PromptAgent::fake([['summary' => 'AI の要約']]);

        $this->assertFailedWith($this->queuedRun(recordId: $record->id), FailedRun::RECORD_NOT_FOUND);
    }

    public function test_a_requester_inactive_on_the_day_of_the_write_fails_before_the_update(): void
    {
        $record = $this->noteRecord();
        $retired = $this->user([['2020-01-01', '2021-01-01']]);
        PromptAgent::fake([['summary' => 'AI の要約']]);

        $runId = $this->queuedRun(recordId: $record->id, context: $this->requesterContext($retired->id));

        $this->assertFailedWith($runId, FailedRun::REQUESTER_INACTIVE);
        $this->assertSame(['summary' => '元の要約'], $this->values($record));
    }

    /**
     * 更新を兼務先の組織(人事部)にだけ許す。兼務の利用者(初期データの u0009。主所属 = 総務部)が人事部を選んで依頼した実行は書け、
     * 選ばずに依頼した実行(主所属で判定される)は書けない。
     */
    public function test_judges_with_the_department_selected_at_the_request(): void
    {
        $record = $this->noteRecord([RecordModuleAction::Read, RecordModuleAction::Create, RecordModuleAction::Delete]);
        $humanResources = Department::query()->where('code', 'human-resources')->sole()->id;
        $this->permitUpdateTo($humanResources);
        $member = User::query()->where('code', 'u0009')->sole()->id;
        PromptAgent::fake([['summary' => 'AI の要約'], ['summary' => '主所属の要約']]);

        $selected = $this->queuedRun(recordId: $record->id, context: $this->requesterContext($member, $humanResources));
        $notSelected = $this->queuedRun(recordId: $record->id, context: $this->requesterContext($member));

        app(ExecutePrompt::class)->execute($selected);
        $this->assertFailedWith($notSelected, FailedRun::RECORD_FORBIDDEN);

        $this->assertSame(['summary' => 'AI の要約'], $this->values($record));
    }

    public function test_cannot_be_called_outside_complete(): void
    {
        $runId = $this->runningRun($this->noteRecord()->id);

        $this->expectException(LogicException::class);

        app(WriteRecordFromRun::class)->execute($runId, new RecordInput(['summary' => 'x']), self::OPERATION);
    }

    public function test_cannot_be_called_for_a_run_that_is_not_running(): void
    {
        $runId = $this->queuedRun(recordId: $this->noteRecord()->id);

        $this->assertLogicErrorDuring($runId, self::OPERATION);
    }

    public function test_cannot_be_called_by_a_kind_without_a_module_or_with_another_module(): void
    {
        $runId = $this->runningRun($this->noteRecord()->id);

        $this->recordingKind->writesTo = null;
        $this->assertLogicErrorDuring($runId, self::OPERATION);
        $this->recordingKind->writesTo = 'other_notes';
        $this->assertLogicErrorDuring($runId, self::OPERATION);
    }

    public function test_the_operation_has_the_shape_of_a_revision_origin(): void
    {
        $runId = $this->runningRun($this->noteRecord()->id);
        $longest = str_repeat('あ', RecordRevisionOrigin::OPERATION_MAX);

        $this->assertThrows(fn () => $this->writeDuringComplete($runId, ''), InvalidArgumentException::class);
        app(CompletingRun::class)->during($runId, function () use ($runId, $longest): void {
            $written = app(WriteRecordFromRun::class)->execute($runId, new RecordInput(['summary' => 'x']), $longest);
            $this->assertTrue($written);
        });
    }

    /**
     * 種類の完了の受け手の書き込み(出力の summary を欄 summary に書く)。
     */
    private function writeSummary(CompletedRun $run): void
    {
        $input = new RecordInput(['summary' => $run->structured['summary'] ?? null]);
        $this->written[] = app(WriteRecordFromRun::class)->execute($run->runId, $input, self::OPERATION);
    }

    /**
     * 業務モジュール ai_notes(項目 summary・memo)と、summary が「元の要約」のレコード。
     *
     * @param  list<RecordModuleAction>|null  $permitted  ゲストと認証済みの全員に許すアクション(既定はすべて)
     */
    private function noteRecord(?array $permitted = null): Record
    {
        $module = $this->recordModule(self::MODULE, $permitted);
        $summary = $this->field($module, 'summary');
        $this->field($module, 'memo');

        return Record::factory()->create([
            'record_module_id' => $module->id,
            'field_values' => [$summary->id => '元の要約'],
        ]);
    }

    /**
     * @return array<string, mixed> 項目のコード → 値(値の無い項目は入れない)
     */
    private function values(Record $record): array
    {
        $codes = RecordField::query()->where('record_module_id', $record->record_module_id)->pluck('code', 'id');
        $values = [];

        foreach (Record::query()->findOrFail($record->id)->field_values ?? [] as $fieldId => $value) {
            $values[(string) $codes[$fieldId]] = $value;
        }

        return $values;
    }

    /**
     * @return list<RecordRevision> 版の番号の順
     */
    private function revisions(int $recordId): array
    {
        $revisions = RecordRevision::query()->where('record_id', $recordId)->orderBy('revision')->get();

        return array_values($revisions->all());
    }

    /**
     * @return array{int, string|null, string|null, int|null, int|null} 版の番号・処理の種別・ステップ名・操作した人・代理元
     */
    private function revisionRow(RecordRevision $revision): array
    {
        return [
            $revision->revision, $revision->origin_operation, $revision->origin_node_name,
            $revision->user_id, $revision->principal_user_id,
        ];
    }

    private function runningRun(int $recordId): int
    {
        $runId = $this->queuedRun(recordId: $recordId);
        AiRun::query()->whereKey($runId)->update(['status' => AiRunStatus::Running, 'started_at' => now()]);

        return $runId;
    }

    private function assertLogicErrorDuring(int $runId, string $operation): void
    {
        $this->assertThrows(fn () => $this->writeDuringComplete($runId, $operation), LogicException::class);
    }

    private function writeDuringComplete(int $runId, string $operation): void
    {
        $write = function () use ($runId, $operation): void {
            app(WriteRecordFromRun::class)->execute($runId, new RecordInput(['summary' => 'x']), $operation);
        };

        app(CompletingRun::class)->during($runId, $write);
    }

    private function assertFailedWith(int $runId, string $reason): void
    {
        $run = $this->executeFailing($runId);

        $this->assertSame([AiRunStatus::Failed, AiRunErrorCode::Completion], [$run->status, $run->error_code]);
        $failed = array_map(fn (FailedRun $run): array => [$run->runId, $run->reason], $this->recordingKind->failed);
        $this->assertSame([[$runId, $reason]], $failed);
    }

    private function permitUpdateTo(int $departmentId): void
    {
        $groupCode = RecordModuleAction::resourceGroupCode(self::MODULE);
        $groupId = AuthzResourceGroup::query()->where('code', $groupCode)->sole()->id;
        $condition = new SubjectConditionInput(departmentId: $departmentId);
        $subject = app(FindOrCreateSingleConditionSubjectGroup::class)->execute($condition, []);
        [$action, $permit] = [RecordModuleAction::Update->value, PolicyEffect::Permit];
        app(CreatePolicy::class)->execute($groupId, $subject->id, RecordModuleAction::RESOURCE_TYPE, $action, $permit);
    }
}
