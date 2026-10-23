<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Queries\Records\Api\RecordAttachmentQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * レコードの添付の読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節の RecordAttachmentQuery):
 * 新しい順・続きの有無・件数、プレビューできるか、期限で実体を消した添付の期限の日。レコードが無ければ 404。
 */
final class RecordAttachmentQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;
    use BuildsUploads;

    public function test_it_returns_the_attachments_newest_first_page_by_page(): void
    {
        $this->fakeUploadDisk();
        $this->limitUploads(request: 5_000);
        $user = $this->user();
        $recordId = Record::factory()->create(['record_module_id' => $this->recordModule('projects')->id])->id;
        app(AttachRecordUploads::class)->execute($this->contextFor($user->id), 'projects', $recordId, [
            $this->uploadedFile('a.pdf'), $this->uploadedFile('b.docx', 'docx'), $this->uploadedFile('c.png', 'png'),
        ]);
        [$a, $b, $c] = RecordAttachment::query()->where('record_id', $recordId)->orderBy('id')->get()->all();
        Upload::query()->whereKey($a->upload_id)->update(['purged_at' => now(), 'purge_due_on' => '2026-10-01']);
        $query = app(RecordAttachmentQuery::class);

        $first = $query->forRecord($recordId, 2);
        $rest = $query->forRecord($recordId, 2, $b->id);

        $previewable = array_map(fn (array $item) => [$item['id'], $item['previewable']], $first['items']);
        $this->assertSame([[$c->id, true], [$b->id, false]], $previewable);
        $this->assertTrue($first['hasMore']);
        $this->assertSame(['name' => 'c.png', 'mime' => 'image/png', 'userId' => $user->id, 'purgeDueOn' => null], [
            'name' => $first['items'][0]['name'], 'mime' => $first['items'][0]['mime'],
            'userId' => $first['items'][0]['userId'], 'purgeDueOn' => $first['items'][0]['purgeDueOn'],
        ]);
        $this->assertSame([[$a->id, $a->upload_id, false, '2026-10-01']], array_map(
            fn (array $item) => [$item['id'], $item['uploadId'], $item['previewable'], $item['purgeDueOn']],
            $rest['items'],
        ));
        $this->assertFalse($rest['hasMore']);
        $this->assertSame(3, $query->countFor($recordId));
    }

    public function test_a_missing_record_is_not_found(): void
    {
        $this->expectException(ModelNotFoundException::class);

        app(RecordAttachmentQuery::class)->countFor(999_999_999);
    }
}
