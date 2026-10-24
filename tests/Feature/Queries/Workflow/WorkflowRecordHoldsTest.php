<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Actions\Records\DeleteRecordField;
use App\Actions\Records\DetachRecordAttachments;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Records\Api\RecordHold;
use App\Queries\Workflow\WorkflowRecordHolds;
use Closure;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの持ち主のワークフローの実装(設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節の実装と操作の表、
 * 設計/ワークフロー/データモデル/直列化と検査.md の 6 節の「ロックの順」の (3)): 一時保存でない案件(未完了・完了)が指すレコードに持ち主
 * (鍵 workflow-case:<案件の id>。文言は未完了・完了で同じ)があり、一時保存の案件だけ・案件の無いレコードには無い。鍵を取らずに読む。
 * 起動の登録を通した本物の実装で、業務データの入れ物の更新・削除・添付の追加と外す・項目の削除が 422 で何も変わらない。
 */
final class WorkflowRecordHoldsTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsUploads;
    use CatchesValidationErrors;

    private const REASON = '申請済みのため変更できません';

    /**
     * @return array<string, array{bool}>
     */
    public static function caseStates(): array
    {
        return ['未完了' => [false], '完了' => [true]];
    }

    #[DataProvider('caseStates')]
    public function test_a_case_that_is_not_a_draft_holds_its_record(bool $completed): void
    {
        $module = $this->recordModule('wf_holds');
        $held = $this->record($module);
        $free = $this->record($module);
        $case = $this->caseOf($module, $held, $completed);

        $this->assertEquals(
            [$held->id => new RecordHold("workflow-case:{$case->id}", self::REASON)],
            $this->holds()->holdsOf([$held->id, $free->id]),
        );
        $this->assertSame([], $this->holds()->holdsOf([]));
    }

    public function test_a_draft_case_and_a_record_without_a_case_hold_nothing(): void
    {
        $module = $this->recordModule('wf_holds');
        $record = $this->record($module);
        WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $this->flowOf($module)->id]);

        $this->assertSame([], $this->holds()->holdsOf([$record->id]));
        $this->assertNull($this->holds()->firstHoldInModule($module->id));
    }

    public function test_the_first_hold_in_a_module_is_its_case_with_the_smallest_id_whether_completed_or_not(): void
    {
        $module = $this->recordModule('wf_holds');
        $other = $this->recordModule('wf_others');
        WorkflowCase::factory()->draft()->create(['workflow_flow_id' => $this->flowOf($module)->id]);
        $first = $this->caseOf($module, $this->record($module), completed: true);
        $this->caseOf($module, $this->record($module));

        $this->assertEquals(
            new RecordHold("workflow-case:{$first->id}", self::REASON),
            $this->holds()->firstHoldInModule($module->id),
        );
        $this->assertNull($this->holds()->firstHoldInModule($other->id));
    }

    /**
     * 持ち主の読み取りは 1 回の問い合わせで完了した案件の持ち主も返し、FOR UPDATE・FOR SHARE を出さない。
     */
    public function test_reads_the_cases_once_without_locks(): void
    {
        $module = $this->recordModule('wf_holds');
        $record = $this->record($module);
        $this->caseOf($module, $record, completed: true);

        $holds = [];
        $holdsOf = $this->queries(function () use ($record, &$holds): void {
            $holds = $this->holds()->holdsOf([$record->id]);
        });
        $firstHold = null;
        $firstHoldQueries = $this->queries(function () use ($module, &$firstHold): void {
            $firstHold = $this->holds()->firstHoldInModule($module->id);
        });

        $this->assertSame([1, 1], [count($holdsOf), count($firstHoldQueries)]);
        $this->assertSame([[$record->id], self::REASON], [array_keys($holds), $firstHold?->reason]);

        foreach ([...$holdsOf, ...$firstHoldQueries] as $sql) {
            $this->assertStringNotContainsString('for update', strtolower($sql));
            $this->assertStringNotContainsString('for share', strtolower($sql));
        }
    }

    /**
     * 起動の登録を通した本物の持ち主で、案件のレコードは業務データの入れ物の更新(鍵なし)・削除・添付の追加と外すが 422 record、
     * 業務モジュールの項目の削除が 422 field(どれも持ち主の理由)で、何も変わらない。
     */
    #[DataProvider('caseStates')]
    public function test_the_registered_holder_blocks_record_writes_while_the_case_exists(bool $completed): void
    {
        $this->fakeUploadDisk();
        $this->limitUploads();
        $module = $this->recordModule('wf_holds');
        $title = $this->field($module, 'title');
        $record = $this->record($module, [$title->id => '旧']);
        $this->attach($record, '前.pdf');
        $attachmentId = RecordAttachment::query()->where('record_id', $record->id)->sole()->id;
        $this->caseOf($module, $record, $completed);
        $context = $this->contextFor();

        $errors = [
            $this->validationErrors(fn () => app(UpdateRecord::class)->execute(
                $context, 'wf_holds', $record->id, new RecordInput(['title' => '新']), $record->refresh()->version(),
            )),
            $this->validationErrors(fn () => app(DeleteRecord::class)->execute($context, 'wf_holds', $record->id)),
            $this->validationErrors(fn () => $this->attach($record, '後.pdf')),
            $this->validationErrors(fn () => app(DetachRecordAttachments::class)
                ->execute($context, 'wf_holds', $record->id, $attachmentId)),
            $this->validationErrors(fn () => app(DeleteRecordField::class)->execute('wf_holds', 'title')),
        ];

        $held = ['record' => self::REASON];
        $this->assertSame([$held, $held, $held, $held, ['field' => self::REASON]], $errors);
        $this->assertEquals([$title->id => '旧'], $record->refresh()->field_values);
        $names = RecordAttachment::query()->where('record_id', $record->id)->pluck('name')->all();
        $this->assertSame(['前.pdf'], $names);
        $this->assertModelExists($title);
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
        $content = WorkflowContent::query()->firstOrCreate(
            ['record_module_id' => $module->id],
            ['code' => "content_{$module->code}", 'name' => $module->code],
        );

        return WorkflowFlow::factory()->create(['workflow_content_id' => $content->id]);
    }

    /**
     * レコードを指す未完了(completed なら完了)の案件。
     */
    private function caseOf(RecordModule $module, Record $record, bool $completed = false): WorkflowCase
    {
        $factory = $completed ? WorkflowCase::factory()->completed() : WorkflowCase::factory()->active();

        return $factory->create(['workflow_flow_id' => $this->flowOf($module)->id, 'record_id' => $record->id]);
    }

    private function attach(Record $record, string $name): void
    {
        $files = [$this->uploadedFile($name)];
        app(AttachRecordUploads::class)->execute($this->contextFor(), 'wf_holds', $record->id, $files);
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
