<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use App\Models\Workflow\WorkflowCase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の削除で消える申請書のレコード(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.7 節の結果と表、
 * 同時実行と検査.md の 14.2 節の案件操作の箇条): 参照されていなければ、未完了・完了のどちらでも申請書のレコードと部品(明細の行・
 * 更新履歴・コメント・添付の行と台帳)が消え、添付の実体はコミットの後に消え、通知は残る。ほかのレコードや互いを参照する申請から
 * 参照されていれば 422 record(参照されているレコードの文言)で、案件・申請書のレコード・部品・実体がすべて残る。管理者 = u0004。
 */
final class DeleteCaseFormRecordTest extends TransactionalTestCase
{
    use BuildsUploads;
    use CatchesValidationErrors;
    use OperatesAsCaseManager;

    private const REFERENCED = ['record' => 'このレコードはほかのデータから参照されているため削除できません'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1());
    }

    /**
     * @return array<string, array{bool}>
     */
    public static function caseStates(): array
    {
        return ['未完了' => [false], '完了' => [true]];
    }

    #[DataProvider('caseStates')]
    public function test_deleting_a_case_deletes_the_form_record_and_its_parts_and_keeps_notices(bool $completed): void
    {
        $case = $this->applyWithParts();

        if ($completed) {
            $this->reject($case, 'section_chief', 'u0002');
        }

        $before = $this->snapshot($case);
        $notices = Notice::query()->where('target_id', $case->id)->count();
        $this->assertNotContains(0, $before['parts']);
        $this->assertNotContains(false, $before['files']);

        $this->deleteCaseAs($case);

        $after = $this->snapshot($case, $before['uploadPaths']);
        $this->assertSame([false, false], [$after['case'], $after['record']]);
        $this->assertSame(array_fill_keys(array_keys($before['parts']), 0), $after['parts']);
        $this->assertSame(array_fill_keys(array_keys($before['files']), false), $after['files']);
        $this->assertSame($notices, Notice::query()->where('target_id', $case->id)->count());
    }

    public function test_a_form_record_referenced_by_another_record_is_rejected_and_nothing_changes(): void
    {
        $case = $this->applyWithParts();
        $other = RecordModule::factory()->createOne();
        $referrer = Record::factory()->createOne(['record_module_id' => $other->id]);
        $this->refer($referrer, $this->referenceField($other), (int) $case->record_id);
        $before = $this->snapshot($case);

        $errors = $this->validationErrors(fn () => $this->deleteCaseAs($case));

        $this->assertSame(self::REFERENCED, $errors);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 互いを参照する申請(申請書のレコードが項目で相手の申請書を指す)は、どちらの順で消しても 422 で、両方が残る。
     */
    public function test_form_records_referring_to_each_other_cannot_be_deleted_in_either_order(): void
    {
        $first = $this->applyWithParts();
        $second = $this->applyWithParts();
        $field = $this->referenceField(RecordModule::query()->where('code', 'project')->sole());
        $this->refer(Record::query()->findOrFail($first->record_id), $field, (int) $second->record_id);
        $this->refer(Record::query()->findOrFail($second->record_id), $field, (int) $first->record_id);
        $before = [$this->snapshot($first), $this->snapshot($second)];

        $errors = [
            $this->validationErrors(fn () => $this->deleteCaseAs($first)),
            $this->validationErrors(fn () => $this->deleteCaseAs($second)),
        ];

        $this->assertSame([self::REFERENCED, self::REFERENCED], $errors);
        $this->assertSame($before, [$this->snapshot($first), $this->snapshot($second)]);
    }

    /**
     * 本物の申請(申請者 u0001。添付 1 つ・明細の行 1 つ・更新履歴の版)の申請書のレコードにコメントを 1 つ足した案件。
     */
    private function applyWithParts(): WorkflowCase
    {
        $base = $this->projectInput(['budget' => 100]);
        $input = new ApplicationInput($base->name, $base->comment, $base->record, [$this->uploadedFile('見積.pdf')]);
        $context = $this->contextOf($this->seededUser('u0001'));
        $date = $this->operationDay()->toDateString();
        $caseId = app(ApplyCase::class)->execute($context, self::FLOW_CODE, $input, $date)->caseId;
        $case = WorkflowCase::query()->findOrFail($caseId);
        $record = Record::query()->findOrFail($case->record_id);
        RecordComment::factory()->createOne([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
        ]);

        return $case;
    }

    /**
     * 案件と申請書のレコードの有無、部品の行の数、添付の実体の有無(実体の置き場は渡したものか、今の台帳の行のもの)。
     *
     * @param  list<string>|null  $uploadPaths
     * @return array{case: bool, record: bool, parts: array<string, int>, uploadPaths: list<string>,
     *     files: array<string, bool>}
     */
    private function snapshot(WorkflowCase $case, ?array $uploadPaths = null): array
    {
        $recordId = (int) $case->record_id;
        $byRecord = fn (string $model): int => $model::query()->where('record_id', $recordId)->count();
        $attachments = RecordAttachment::query()->where('record_id', $recordId);
        $uploads = Upload::query()->whereKey($attachments->clone()->pluck('upload_id'));
        $paths = $uploadPaths ?? array_values($uploads->pluck('path')->all());

        return [
            'case' => WorkflowCase::query()->whereKey($case->id)->exists(),
            'record' => Record::query()->whereKey($recordId)->exists(),
            'parts' => [
                'detail_rows' => Record::query()->where('parent_record_id', $recordId)->count(),
                'revisions' => $byRecord(RecordRevision::class),
                'comments' => $byRecord(RecordComment::class),
                'attachments' => $attachments->count(),
                'uploads' => Upload::query()->whereIn('path', $paths)->count(),
            ],
            'uploadPaths' => $paths,
            'files' => array_combine($paths, array_map(Storage::disk('local')->exists(...), $paths)),
        ];
    }

    /**
     * 業務モジュールに足す、業務モジュール project を指す他モジュール参照の項目。
     */
    private function referenceField(RecordModule $module): RecordField
    {
        $project = RecordModule::query()->where('code', 'project')->sole();

        return RecordField::factory()->reference()->createOne([
            'record_module_id' => $module->id,
            'referenced_record_module_id' => $project->id,
        ]);
    }

    private function refer(Record $record, RecordField $field, int $targetId): void
    {
        RecordReference::factory()->createOne([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'record_field_id' => $field->id,
            'user_id' => null,
            'referenced_record_module_id' => $field->referenced_record_module_id,
            'referenced_record_id' => $targetId,
        ]);
    }
}
