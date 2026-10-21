<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\DetachRecordAttachments;
use App\Actions\Records\RecordAttachmentGate;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 業務の画面とワークフローの添付の追加・外す(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の
 * 1 節・2 節・5 節、検査 = 10 節の「判定」「使用」「レコードの版」「持ち主」「外す」、更新履歴.md の 9 節の添付の行と「契機」の代理)。
 */
final class RecordAttachmentWritesTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use BuildsUploads;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    private RecordModule $module;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
        $this->module = $this->recordModule('projects');
        $this->field($this->module, 'title');
    }

    public function test_attaching_writes_the_rows_and_a_revision_but_not_the_record(): void
    {
        $user = $this->user();
        $recordId = $this->createRecord(['title' => '企画'], $user->id);
        $before = Record::query()->findOrFail($recordId);
        $this->travel(1)->minutes();

        $this->attach($recordId, [$this->uploadedFile('見積.pdf'), $this->uploadedFile('図.png', 'png')], $user->id);

        $after = Record::query()->findOrFail($recordId);
        $this->assertSame(
            [$before->updated_at?->toIso8601String(), $before->updated_by_user_id, $before->version()],
            [$after->updated_at?->toIso8601String(), $after->updated_by_user_id, $after->version()],
        );
        $attachments = RecordAttachment::query()->where('record_id', $recordId)->orderBy('id')->get();
        $this->assertSame(['見積.pdf', '図.png'], $attachments->pluck('name')->all());
        $this->assertSame([$user->id, $user->id], $attachments->pluck('user_id')->all());
        $uploaders = Upload::query()->whereKey($attachments->pluck('upload_id'))->pluck('user_id')->all();
        $this->assertSame([$user->id, $user->id], $uploaders);
        $latest = $this->latestRevision($recordId);
        $this->assertSame(2, $latest->revision);
        $this->assertSame(['見積.pdf', '図.png'], array_column(($latest->snapshot ?? [])['attachments'] ?? [], 'name'));
    }

    public function test_detaching_keeps_the_upload_and_the_file_and_writes_a_revision(): void
    {
        $recordId = $this->createRecord(['title' => '企画']);
        $this->attach($recordId, [$this->uploadedFile('a.pdf')]);
        $attachment = RecordAttachment::query()->where('record_id', $recordId)->sole();
        $total = (int) Upload::query()->sum('size');
        $version = Record::query()->findOrFail($recordId)->version();

        $this->detach($recordId, $attachment->id);

        $this->assertFalse(RecordAttachment::query()->whereKey($attachment->id)->exists());
        $upload = Upload::query()->findOrFail($attachment->upload_id);
        Storage::disk('local')->assertExists($upload->path);
        $this->assertSame($total, (int) Upload::query()->sum('size'));
        $this->assertSame($version, Record::query()->findOrFail($recordId)->version());
        $latest = $this->latestRevision($recordId);
        $this->assertSame([3, []], [$latest->revision, ($latest->snapshot ?? [])['attachments'] ?? null]);
    }

    public function test_the_diff_between_revisions_shows_the_added_and_the_removed_attachment(): void
    {
        $recordId = $this->createRecord(['title' => '企画']);
        $this->attach($recordId, [$this->uploadedFile('a.pdf')]);
        $attachment = RecordAttachment::query()->where('record_id', $recordId)->sole();
        $this->detach($recordId, $attachment->id);
        $revisions = RecordRevision::query()->where('record_id', $recordId)->orderBy('revision');
        [$first, $added, $removed] = $revisions->pluck('id')->all();
        $diff = fn (int $before, int $after) => app(RecordRevisionQuery::class)
            ->diffBetween(new Requester(null, null), $before, $after, now(), 'ja')['attachments'];

        $this->assertSame(
            [['change' => 'added', 'id' => $attachment->id, 'uploadId' => $attachment->upload_id, 'name' => 'a.pdf']],
            $diff($first, $added),
        );
        $this->assertSame(
            [['change' => 'removed', 'id' => $attachment->id, 'uploadId' => $attachment->upload_id, 'name' => 'a.pdf']],
            $diff($added, $removed),
        );
    }

    public function test_a_reader_cannot_attach_or_detach_and_an_updater_can_attach(): void
    {
        $this->recordModule('readonly', [RecordModuleAction::Read]);
        $this->recordModule('writeonly', [RecordModuleAction::Update]);
        $readonly = Record::factory()->create(['record_module_id' => $this->moduleId('readonly')])->id;
        $writeonly = Record::factory()->create(['record_module_id' => $this->moduleId('writeonly')])->id;

        $this->assertThrows(
            fn () => $this->attach($readonly, [$this->uploadedFile('a.pdf')], module: 'readonly'),
            AuthorizationException::class,
        );
        $this->assertThrows(
            fn () => $this->detach($readonly, 1, 'readonly'),
            AuthorizationException::class,
        );

        $this->attach($writeonly, [$this->uploadedFile('a.pdf')], module: 'writeonly');
        $this->assertSame(1, RecordAttachment::query()->where('record_id', $writeonly)->count());
    }

    public function test_a_module_not_using_attachments_rejects_attaching_and_detaching(): void
    {
        $recordId = $this->createRecord(['title' => '企画']);
        $this->attach($recordId, [$this->uploadedFile('a.pdf')]);
        $attachmentId = RecordAttachment::query()->where('record_id', $recordId)->sole()->id;
        $this->module->update(['is_attachment_enabled' => false]);
        $expected = ['attachments' => RecordAttachmentGate::DISABLED];

        $file = $this->uploadedFile('b.pdf');
        $this->assertSame($expected, $this->validationErrors(fn () => $this->attach($recordId, [$file])));
        $this->assertSame($expected, $this->validationErrors(
            fn () => $this->detach($recordId, $attachmentId),
        ));
        $this->assertSame(1, RecordAttachment::query()->where('record_id', $recordId)->count());
        $this->assertSame(1, $this->storedFileCount());
    }

    public function test_a_details_row_and_a_details_module_cannot_get_attachments(): void
    {
        $tasks = $this->detailsField($this->module, 'tasks');
        $parent = $this->createRecord(['title' => '企画']);
        $row = Record::factory()->create([
            'record_module_id' => $tasks->child_record_module_id,
            'parent_record_module_id' => $this->module->id,
            'parent_record_id' => $parent,
        ]);

        $file = $this->uploadedFile('a.pdf');
        $this->assertThrows(fn () => $this->attach($row->id, [$file]), ModelNotFoundException::class);
        $inDetailsModule = fn () => $this->attach($row->id, [$file], module: 'tasks_rows');
        $this->assertThrows($inDetailsModule, ModelNotFoundException::class);
        $this->assertSame(0, Upload::query()->count());
    }

    public function test_another_records_attachment_is_not_found_on_detach(): void
    {
        $first = $this->createRecord(['title' => 'A']);
        $second = $this->createRecord(['title' => 'B']);
        $this->attach($first, [$this->uploadedFile('a.pdf')]);
        $attachmentId = RecordAttachment::query()->where('record_id', $first)->sole()->id;

        $this->assertThrows(
            fn () => $this->detach($second, $attachmentId),
            ModelNotFoundException::class,
        );
    }

    public function test_a_held_record_rejects_the_screen_and_accepts_the_holder_without_update(): void
    {
        $this->recordModule('held', [RecordModuleAction::Read]);
        $recordId = Record::factory()->create(['record_module_id' => $this->moduleId('held')])->id;
        $this->registerRecordHolds([$recordId => 'case:1']);
        $projectId = $this->createRecord(['title' => '企画']);
        $this->attach($projectId, [$this->uploadedFile('a.pdf')]);
        $attachmentId = RecordAttachment::query()->where('record_id', $projectId)->sole()->id;
        $this->registerRecordHolds([$projectId => 'case:2']);

        $file = $this->uploadedFile('b.pdf');
        $errors = $this->validationErrors(fn () => $this->attach($projectId, [$file]));
        $this->assertSame(['record' => self::HOLD_REASON], $errors);
        $this->assertSame(['record' => self::HOLD_REASON], $this->validationErrors(
            fn () => $this->detach($projectId, $attachmentId),
        ));

        $file = $this->uploadedFile('c.pdf');
        app(AttachRecordUploads::class)->execute($this->contextFor(), 'held', $recordId, [$file], 'case:1');
        $this->assertSame(['c.pdf'], RecordAttachment::query()->where('record_id', $recordId)->pluck('name')->all());
    }

    public function test_a_proxy_origin_records_the_executor_on_the_rows_and_the_revision(): void
    {
        [$principal, $executor] = [$this->user(), $this->user()];
        $recordId = $this->createRecord(['title' => '企画']);

        app(AttachRecordUploads::class)->execute(
            $this->contextFor($principal->id),
            'projects',
            $recordId,
            [$this->uploadedFile('a.pdf')],
            origin: new RecordRevisionOrigin('承認', '部長', $executor->id),
        );

        $attachment = RecordAttachment::query()->where('record_id', $recordId)->sole();
        $latest = $this->latestRevision($recordId);
        $uploader = Upload::query()->findOrFail($attachment->upload_id)->user_id;
        $this->assertSame([$executor->id, $executor->id], [$attachment->user_id, $uploader]);
        $this->assertSame(
            [$executor->id, $principal->id, '承認', '部長'],
            [$latest->user_id, $latest->principal_user_id, $latest->origin_operation, $latest->origin_node_name],
        );
        $this->assertThrows(fn () => app(AttachRecordUploads::class)->execute(
            $this->contextFor($principal->id),
            'projects',
            $recordId,
            [$this->uploadedFile('b.pdf')],
            origin: new RecordRevisionOrigin('承認', null, $principal->id),
        ), InvalidArgumentException::class);
    }

    public function test_no_files_are_rejected(): void
    {
        $recordId = $this->createRecord(['title' => '企画']);

        $errors = $this->validationErrors(fn () => $this->attach($recordId, []));

        $this->assertSame(['attachments' => 'ファイルを選択してください'], $errors);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function createRecord(array $values, ?int $userId = null): int
    {
        return app(CreateRecord::class)->execute($this->contextFor($userId), 'projects', new RecordInput($values));
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function attach(int $recordId, array $files, ?int $userId = null, string $module = 'projects'): void
    {
        app(AttachRecordUploads::class)->execute($this->contextFor($userId), $module, $recordId, $files);
    }

    private function detach(int $recordId, int $attachmentId, string $module = 'projects'): void
    {
        app(DetachRecordAttachments::class)->execute($this->contextFor(), $module, $recordId, $attachmentId);
    }

    private function latestRevision(int $recordId): RecordRevision
    {
        return RecordRevision::query()->where('record_id', $recordId)->orderByDesc('revision')->firstOrFail();
    }

    private function moduleId(string $code): int
    {
        return RecordModule::query()->where('code', $code)->sole()->id;
    }
}
