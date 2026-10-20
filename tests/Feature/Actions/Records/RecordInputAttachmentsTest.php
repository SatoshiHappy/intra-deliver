<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\RecordUploadInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Actions\Records\DetachRecordAttachments;
use App\Actions\Records\RecordAttachmentWriter;
use App\Actions\Upload\Api\StoreUploads;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 登録・更新の口の添付(申請の口 = docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節・7 節、
 * 検査 = 10 節の「申請の口」「削除」)と、鍵の順(業務モジュールの鍵 → 行 → 台帳 = 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 4 節)。
 */
final class RecordInputAttachmentsTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use BuildsUploads;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    private RecordModule $module;

    /** 鍵の順を記録している間だけ真(登録した聞き手はテストの終わりまで残るので、記録の外では何もしない) */
    private bool $recording = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
        $this->module = $this->recordModule('projects');
        $this->field($this->module, 'title');
    }

    public function test_a_registration_with_uploads_and_files_writes_one_revision_with_both(): void
    {
        $uploadIds = $this->ownerlessUploads(4);
        $uploads = array_map(fn (int $id) => new RecordUploadInput($id, "draft{$id}.pdf"), $uploadIds);

        $files = [$this->uploadedFile('new.png', 'png')];
        $recordId = $this->create(new RecordInput(['title' => '申請'], [], $files, $uploads));

        $revisions = RecordRevision::query()->where('record_id', $recordId)->get();
        $this->assertCount(1, $revisions);
        $snapshot = $revisions->sole()->snapshot ?? [];
        $this->assertSame(
            [...array_map(fn (int $id) => "draft{$id}.pdf", $uploadIds), 'new.png'],
            array_column($snapshot['attachments'] ?? [], 'name'),
        );
        $this->assertSame([$this->titleId() => '申請'], $snapshot['values'] ?? null);
        $this->assertSame($uploadIds, array_slice(array_column($snapshot['attachments'] ?? [], 'upload_id'), 0, 4));
    }

    public function test_an_update_with_files_through_the_holder_writes_one_revision(): void
    {
        $recordId = $this->create(new RecordInput(['title' => '申請']));
        $this->registerRecordHolds([$recordId => 'case:1']);

        app(UpdateRecord::class)->execute(
            $this->contextFor(),
            'projects',
            $recordId,
            new RecordInput(['title' => '再申請'], [], [$this->uploadedFile('a.pdf')]),
            null,
            'case:1',
            new RecordRevisionOrigin('再申請', '申請'),
        );

        $latest = RecordRevision::query()->where('record_id', $recordId)->orderByDesc('revision')->firstOrFail();
        $this->assertSame(2, $latest->revision);
        $this->assertSame(['a.pdf'], array_column(($latest->snapshot ?? [])['attachments'] ?? [], 'name'));
    }

    /**
     * @return array<string, array{Closure(self, int): int}>
     */
    public static function ownedUploads(): array
    {
        return [
            'a detached upload' => [fn (self $test, int $recordId) => $test->detachedUpload($recordId)],
            'an attachment of another record' => [fn (self $test, int $recordId) => $test->attachedUpload($recordId)],
            'a missing upload' => [fn (self $test, int $recordId) => 999_999_999],
        ];
    }

    /**
     * @param  Closure(self, int): int  $owned
     */
    #[DataProvider('ownedUploads')]
    public function test_an_upload_with_an_owner_is_a_mistake_of_the_caller(Closure $owned): void
    {
        $other = $this->create(new RecordInput(['title' => '別']));
        $uploadId = $owned($this, $other);
        $records = Record::query()->count();

        $this->assertThrows(
            fn () => $this->create(new RecordInput(['title' => '申請'], [], [], [new RecordUploadInput($uploadId, 'a')])),
            InvalidArgumentException::class,
        );
        $this->assertSame($records, Record::query()->count());
    }

    public function test_the_same_upload_twice_is_a_mistake_of_the_caller(): void
    {
        [$uploadId] = $this->ownerlessUploads(1);
        $twice = [new RecordUploadInput($uploadId, 'a.pdf'), new RecordUploadInput($uploadId, 'a.pdf')];

        $create = fn () => $this->create(new RecordInput(['title' => '申請'], [], [], $twice));
        $this->assertThrows($create, InvalidArgumentException::class);
    }

    public function test_a_module_not_using_attachments_rejects_attachments_in_the_input(): void
    {
        $this->module->update(['is_attachment_enabled' => false]);

        $input = new RecordInput(['title' => '申請'], [], [$this->uploadedFile('a.pdf')]);
        $errors = $this->validationErrors(fn () => $this->create($input));

        $this->assertSame(['attachments' => RecordAttachmentWriter::DISABLED], $errors);
        $this->assertSame(0, Upload::query()->count());
    }

    /**
     * どの経路も、業務モジュールの共有の鍵 → (更新・添付は)行の鍵 → 台帳の鍵の順に取る(台帳の鍵が最後 = 待ちの輪を作らない)。
     */
    public function test_every_path_takes_the_module_then_the_row_then_the_ledger_lock(): void
    {
        $recordId = $this->create(new RecordInput(['title' => '申請']));
        $paths = [
            'create' => fn () => $this->create(new RecordInput(['title' => 'B'], [], [$this->uploadedFile('a.pdf')])),
            'update' => fn () => app(UpdateRecord::class)->execute(
                $this->contextFor(),
                'projects',
                $recordId,
                new RecordInput([], [], [$this->uploadedFile('b.pdf')]),
                null,
            ),
            'attach' => fn () => $this->attach($recordId, 'c.pdf'),
        ];

        foreach ($paths as $name => $path) {
            $locks = $this->locksOf($path);
            $expected = $name === 'create' ? ['module', 'ledger'] : ['module', 'row', 'ledger'];
            $this->assertSame($expected, $locks, $name);
        }
    }

    public function test_deleting_a_record_deletes_the_current_and_the_detached_uploads_after_the_commit(): void
    {
        $files = [$this->uploadedFile('a.pdf'), $this->uploadedFile('b.pdf')];
        $recordId = $this->create(new RecordInput(['title' => '申請'], [], $files));
        $detached = $this->detachedUpload($recordId);
        $current = RecordAttachment::query()->where('record_id', $recordId)->pluck('upload_id')->all();
        $uploadIds = [...$current, $detached];
        $paths = Upload::query()->whereKey($uploadIds)->pluck('path')->all();

        app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $recordId);

        $this->assertSame(0, Upload::query()->whereKey($uploadIds)->count());
        Storage::disk('local')->assertMissing($paths);
        $this->assertFalse(Record::query()->whereKey($recordId)->exists());
    }

    public function test_a_rejected_delete_of_a_referenced_record_removes_nothing(): void
    {
        $recordId = $this->create(new RecordInput(['title' => '申請'], [], [$this->uploadedFile('a.pdf')]));
        $referring = $this->recordModule('reports');
        $this->referenceField($referring, 'project', $this->module);
        app(CreateRecord::class)->execute($this->contextFor(), 'reports', new RecordInput(['project' => $recordId]));
        $attachment = RecordAttachment::query()->where('record_id', $recordId)->sole();

        $delete = fn () => app(DeleteRecord::class)->execute($this->contextFor(), 'projects', $recordId);
        $errors = $this->validationErrors($delete);

        $this->assertSame(['record'], array_keys($errors));
        $this->assertTrue(RecordAttachment::query()->whereKey($attachment->id)->exists());
        Storage::disk('local')->assertExists(Upload::query()->findOrFail($attachment->upload_id)->path);
    }

    /**
     * そのレコードに付けて外した実体(版の写しだけが指す)。
     */
    public function detachedUpload(int $recordId): int
    {
        $this->attach($recordId, 'old.pdf');
        $attachment = RecordAttachment::query()->where('record_id', $recordId)->orderByDesc('id')->firstOrFail();
        app(DetachRecordAttachments::class)->execute($this->contextFor(), 'projects', $recordId, $attachment->id);

        return $attachment->upload_id;
    }

    /**
     * そのレコードの今の添付の実体。
     */
    public function attachedUpload(int $recordId): int
    {
        $this->attach($recordId, 'now.pdf');

        return RecordAttachment::query()->where('record_id', $recordId)->orderByDesc('id')->firstOrFail()->upload_id;
    }

    private function attach(int $recordId, string $name): void
    {
        $files = [$this->uploadedFile($name)];
        app(AttachRecordUploads::class)->execute($this->contextFor(), 'projects', $recordId, $files);
    }

    private function create(RecordInput $input): int
    {
        return app(CreateRecord::class)->execute($this->contextFor(), 'projects', $input);
    }

    /**
     * 持ち主の無い実体(一時保存の添付に当たる。3 つずつ上げる)。
     *
     * @return list<int>
     */
    private function ownerlessUploads(int $count): array
    {
        $ids = [];

        foreach (array_chunk(range(1, $count), 3) as $chunk) {
            $files = array_map(fn (int $index) => $this->uploadedFile("draft{$index}.pdf"), $chunk);
            $ids = [...$ids, ...app(StoreUploads::class)->execute(null, $files)];
        }

        return $ids;
    }

    private function titleId(): int
    {
        return RecordField::query()->where('record_module_id', $this->module->id)->where('code', 'title')->sole()->id;
    }

    /**
     * 処理が取った鍵の順(業務モジュールの共有の勧告ロック = module、行の FOR NO KEY UPDATE = row、台帳の勧告ロック = ledger)。
     *
     * @param  Closure(): mixed  $path
     * @return list<string>
     */
    private function locksOf(Closure $path): array
    {
        $locks = [];
        $this->recording = true;
        DB::listen(function (QueryExecuted $query) use (&$locks) {
            if (! $this->recording) {
                return;
            }

            $sql = $query->sql;
            $locks[] = match (true) {
                str_contains($sql, 'pg_advisory_xact_lock_shared(') => 'module',
                str_contains($sql, 'for no key update') && str_contains($sql, 'from "records"') => 'row',
                str_contains($sql, 'pg_advisory_xact_lock(')
                    && $query->bindings === [StoreUploads::LEDGER_LOCK_KEY] => 'ledger',
                default => null,
            };
        });
        $path();
        $this->recording = false;

        return array_values(array_filter($locks));
    }
}
