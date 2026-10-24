<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\DeleteRecordUnchecked;
use App\Actions\Records\DetachRecordAttachments;
use App\Actions\Records\LockedRecordDeletion;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordComment;
use App\Models\Records\RecordCommentMention;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsRecordComments;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 判定しない削除の公開の口(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2 の「削除の結果」の表の
 * 判定しない行、保証 = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表、消える順 = 設計/業務データの入れ物/レコードの共通機能/
 * 添付ファイル/レコードの添付.md の 6 節)。判定の入力を受けないこと・デッドロックの伝わり方は DeleteRecordUncheckedAuthorizationTest。
 */
final class DeleteRecordUncheckedTest extends TransactionalTestCase
{
    use BuildsRecordComments;
    use BuildsUploads;
    use CatchesValidationErrors;
    use RegistersRecordHolds;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_a_missing_record_and_a_details_row_are_not_found(): void
    {
        $fixture = $this->rowAccessFixture();
        $row = Record::query()->where('parent_record_id', $fixture['recordId'])->firstOrFail();

        foreach ([999_999_999, $row->id] as $recordId) {
            $this->assertThrows(fn () => $this->deleteUnchecked($recordId), ModelNotFoundException::class);
        }

        $this->assertModelExists($row);
    }

    /**
     * 添付・版・コメント・コメントの宛先・明細の行・参照の値が消え、添付と版が指した実体は外側のコミットの後に消える。
     */
    public function test_deletes_the_record_with_its_parts_and_the_files_after_the_outer_commit(): void
    {
        $fixture = $this->rowAccessFixture();
        $recordId = $fixture['recordId'];
        $rowIds = Record::query()->where('parent_record_id', $recordId)->pluck('id')->all();
        $ownIds = [$recordId, ...array_values(array_map(intval(...), $rowIds))];
        $uploadIds = $this->attachTwoAndDetachOne($fixture['people']['creator']->id, $recordId);
        $this->postComment($fixture['people']['creator']->id, $recordId, '確認します', [$fixture['people']['attendee']->id]);
        $paths = Upload::query()->whereKey($uploadIds)->pluck('path')->all();
        $before = $this->partCounts($ownIds, $uploadIds);
        $this->assertSame([2, 1, 1], [$before['uploads'], $before['attachments'], $before['mentions']]);
        $this->assertNotContains(0, $before);

        DB::transaction(function () use ($recordId, $paths): void {
            $this->deleteUnchecked($recordId);
            Storage::disk('local')->assertExists($paths);
        });

        Storage::disk('local')->assertMissing($paths);
        $this->assertSame(array_fill_keys(array_keys($before), 0), $this->partCounts($ownIds, $uploadIds));
    }

    public function test_the_record_and_the_files_remain_when_the_outer_transaction_rolls_back(): void
    {
        $fixture = $this->rowAccessFixture();
        $recordId = $fixture['recordId'];
        $uploadIds = $this->attachTwoAndDetachOne($fixture['people']['creator']->id, $recordId);
        $paths = Upload::query()->whereKey($uploadIds)->pluck('path')->all();

        $this->assertThrows(fn () => DB::transaction(function () use ($recordId): void {
            $this->deleteUnchecked($recordId);

            throw new RuntimeException('外側の失敗');
        }), RuntimeException::class, '外側の失敗');

        $this->assertTrue(Record::query()->whereKey($recordId)->exists());
        $this->assertSame(2, Upload::query()->whereKey($uploadIds)->count());
        Storage::disk('local')->assertExists($paths);
    }

    /**
     * 持ち主があれば 422 record(文言 = 持ち主の理由)で何も消えず、持ち主の実装が持ち主を外した後に同じトランザクションで呼ぶと消える。
     */
    public function test_a_held_record_is_rejected_until_the_holder_releases_it(): void
    {
        $record = $this->recordOf($this->recordModule('applications'));
        $holds = $this->registerRecordHolds([$record->id => 'workflow-case:1']);

        $errors = $this->validationErrors(fn () => $this->deleteUnchecked($record->id));

        $this->assertSame(['record' => '申請済みのため変更できません'], $errors);
        $this->assertModelExists($record);

        DB::transaction(function () use ($holds, $record): void {
            $holds->release($record->id);
            $this->deleteUnchecked($record->id);
        });

        $this->assertModelMissing($record);
    }

    /**
     * 参照されていれば 422 record で、外側のトランザクションごと巻き戻る(外側で先に書いた行も残らない)。持ち主もあれば持ち主の 422 が先。
     */
    public function test_a_referenced_record_is_rejected_and_the_outer_transaction_rolls_back(): void
    {
        $applications = $this->recordModule('applications');
        $record = $this->recordOf($applications);
        $reports = $this->recordModule('reports');
        $referring = $this->recordOf($reports);
        $field = $this->referenceField($reports, 'application', $applications);
        RecordReference::factory()->create([
            'record_module_id' => $reports->id,
            'record_id' => $referring->id,
            'record_field_id' => $field->id,
            'user_id' => null,
            'referenced_record_module_id' => $applications->id,
            'referenced_record_id' => $record->id,
        ]);
        $written = null;

        $errors = $this->validationErrors(function () use ($applications, $record, &$written): void {
            DB::transaction(function () use ($applications, $record, &$written): void {
                $written = $this->recordOf($applications);
                $this->deleteUnchecked($record->id);
            });
        });

        $this->assertSame(['record' => LockedRecordDeletion::REFERENCED], $errors);
        $this->assertModelExists($record);
        $this->assertNotNull($written);
        $this->assertModelMissing($written);

        $this->registerRecordHolds([$record->id => 'workflow-case:1'], '申請済みです');
        $this->assertSame(
            ['record' => '申請済みです'],
            $this->validationErrors(fn () => $this->deleteUnchecked($record->id)),
        );
    }

    private function deleteUnchecked(int $recordId): void
    {
        app(DeleteRecordUnchecked::class)->execute($recordId);
    }

    private function recordOf(RecordModule $module): Record
    {
        return Record::factory()->create(['record_module_id' => $module->id]);
    }

    /**
     * 添付を 2 つ付けて 1 つ外す(今の添付 1 つと、版の写しだけが指す実体 1 つ)。2 つの実体の id を返す。
     *
     * @return list<int>
     */
    private function attachTwoAndDetachOne(int $userId, int $recordId): array
    {
        $context = $this->contextFor($userId);
        $files = [$this->uploadedFile('a.pdf'), $this->uploadedFile('b.pdf')];
        app(AttachRecordUploads::class)->execute($context, 'meetings', $recordId, $files);
        $attachments = RecordAttachment::query()->where('record_id', $recordId)->orderBy('id')->get();
        app(DetachRecordAttachments::class)->execute($context, 'meetings', $recordId, $attachments->firstOrFail()->id);

        return array_values(array_map(intval(...), $attachments->pluck('upload_id')->all()));
    }

    /**
     * レコード(親と明細の行)と一緒に消える行と、実体の台帳の行の数。
     *
     * @param  list<int>  $ownIds  親のレコードと明細の行の id
     * @param  list<int>  $uploadIds
     * @return array<string, int>
     */
    private function partCounts(array $ownIds, array $uploadIds): array
    {
        $comments = RecordComment::query()->select('id')->whereIn('record_id', $ownIds);

        return [
            'records' => Record::query()->whereKey($ownIds)->count(),
            'attachments' => RecordAttachment::query()->whereIn('record_id', $ownIds)->count(),
            'revisions' => RecordRevision::query()->whereIn('record_id', $ownIds)->count(),
            'comments' => RecordComment::query()->whereIn('record_id', $ownIds)->count(),
            'mentions' => RecordCommentMention::query()->whereIn('record_comment_id', $comments)->count(),
            'references' => RecordReference::query()->whereIn('record_id', $ownIds)->count(),
            'uploads' => Upload::query()->whereKey($uploadIds)->count(),
        ];
    }
}
