<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\DeleteRecordUnchecked;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Actions\Records\DetachRecordAttachments;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use Closure;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;
use Tests\Support\TransactionAttemptsRecorder;

/**
 * レコードの書き込み(登録・更新・削除・判定しない削除・添付の追加と外す)の業務処理は、トランザクションを 3 回まで試す(デッドロック・
 * 直列化の失敗のときだけ Laravel がやり直す = App\Actions\Records\RecordWriteTransaction)。テストのトランザクションの中ではやり直しが
 * 起きないので、業務処理が最初に DB::transaction に渡した回数で確かめる。
 */
final class RecordWriteRetryTest extends TransactionalTestCase
{
    use BuildsRecordModules;
    use BuildsUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
        $this->field($this->recordModule('projects'), 'title');
    }

    public function test_record_writes_try_the_transaction_up_to_3_times(): void
    {
        $context = $this->contextFor();
        $recordId = 0;
        $created = $this->attemptsOf(function () use ($context, &$recordId): void {
            $recordId = app(CreateRecord::class)->execute($context, 'projects', new RecordInput(['title' => '企画']));
        });
        $version = Record::query()->findOrFail($recordId)->version();
        $updated = $this->attemptsOf(fn () => app(UpdateRecord::class)->execute(
            $context, 'projects', $recordId, new RecordInput(['title' => '変更']), $version,
        ));
        $attached = $this->attemptsOf(fn () => app(AttachRecordUploads::class)->execute(
            $context, 'projects', $recordId, [$this->uploadedFile('見積.pdf')],
        ));
        $attachmentId = RecordAttachment::query()->where('record_id', $recordId)->sole()->id;
        $detach = app(DetachRecordAttachments::class);
        $detached = $this->attemptsOf(fn () => $detach->execute($context, 'projects', $recordId, $attachmentId));
        $deleted = $this->attemptsOf(fn () => app(DeleteRecord::class)->execute($context, 'projects', $recordId));
        $other = app(CreateRecord::class)->execute($context, 'projects', new RecordInput(['title' => '別']));
        $uncheckedDeleted = $this->attemptsOf(fn () => app(DeleteRecordUnchecked::class)->execute($other));

        $this->assertSame([3, 3, 3, 3, 3, 3], [$created, $updated, $attached, $detached, $deleted, $uncheckedDeleted]);
        $this->assertFalse(Record::query()->whereKey([$recordId, $other])->exists());
    }

    /**
     * 処理が最初に DB::transaction に渡した試行の回数(呼ばなければ null)。
     */
    private function attemptsOf(Closure $action): ?int
    {
        $original = DB::getFacadeRoot();
        $recorder = new TransactionAttemptsRecorder($original);
        DB::swap($recorder);

        try {
            $action();
        } finally {
            DB::swap($original);
        }

        return $recorder->attempts[0] ?? null;
    }
}
