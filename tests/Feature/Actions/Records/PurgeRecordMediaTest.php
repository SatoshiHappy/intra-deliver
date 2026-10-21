<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\AttachRecordUploads;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\DeleteRecord;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordRevision;
use App\Models\Upload\Upload;
use App\Queries\Records\Api\RecordAttachmentQuery;
use App\Queries\Records\Api\RecordDueQuery;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsPurgeTargets;
use Tests\Support\BuildsRecordModules;
use Tests\Support\BuildsUploads;
use Tests\Support\RegistersRecordHolds;
use Tests\Support\TransactionalTestCase;

/**
 * 期限の削除(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節・5 節、検査 = 10 節の「期限の削除」):
 * 今の添付と外した添付の音声・動画の実体だけが消え、行・版・records の行は変わらない。期限は口が行の鍵の下で読み直した値で確かめる。
 */
final class PurgeRecordMediaTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsPurgeTargets;
    use BuildsRecordModules;
    use BuildsUploads;
    use RegistersRecordHolds;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-21 03:00:00 UTC');
        $this->fakeUploadDisk();
        $this->limitUploads(1_000, 3_000);
        $this->meetingsModule();
    }

    public function test_the_media_of_the_current_and_the_removed_attachments_is_purged_and_the_rest_stays(): void
    {
        $recordId = $this->meeting('2026-10-20', [
            $this->uploadedFile('a.mp3', 'mp3'), $this->uploadedFile('b.pdf'), $this->uploadedFile('c.png', 'png'),
        ]);
        $this->attachAndDetach($recordId, $this->uploadedFile('d.mp4', 'mp4'));
        $record = Record::query()->findOrFail($recordId);
        $rows = RecordAttachment::query()->orderBy('id')->get()->toArray();
        $revisions = RecordRevision::query()->where('record_id', $recordId)->orderBy('revision')->get()->toArray();
        $keptMimes = ['application/pdf', 'image/png'];
        $kept = (int) Upload::query()->whereNull('purged_at')->whereIn('mime', $keptMimes)->sum('size');

        $count = $this->purge($recordId);

        $this->assertSame(2, $count);
        $purged = Upload::query()->whereNotNull('purged_at')->orderBy('id')->get();
        $this->assertSame(['audio/mpeg', 'video/mp4'], $purged->pluck('mime')->all());
        $dues = $purged->map(fn (Upload $upload) => $upload->purge_due_on?->toDateString())->all();
        $this->assertSame(['2026-10-20', '2026-10-20'], $dues);
        $this->assertSame(2, $this->storedFileCount());
        $this->assertSame($kept, (int) Upload::query()->whereNull('purged_at')->sum('size'));
        $this->assertSame($rows, RecordAttachment::query()->orderBy('id')->get()->toArray());
        $after = RecordRevision::query()->where('record_id', $recordId)->orderBy('revision')->get()->toArray();
        $this->assertSame($revisions, $after);
        $this->assertSame($record->updated_at?->toIso8601String(), $record->refresh()->updated_at?->toIso8601String());
        $this->assertSame(0, $this->purge($recordId));
    }

    public function test_audio_added_after_a_purge_goes_at_the_next_call_with_that_calls_due_date(): void
    {
        $recordId = $this->meeting('2026-10-20', [$this->uploadedFile('a.mp3', 'mp3')]);
        $firstCount = $this->purge($recordId);
        app(AttachRecordUploads::class)
            ->execute($this->contextFor(), 'meetings', $recordId, [$this->uploadedFile('b.wav', 'wav')]);
        $version = Record::query()->findOrFail($recordId)->version();
        $earlier = new RecordInput(['purge_on' => '2026-10-19']);
        app(UpdateRecord::class)->execute($this->contextFor(), 'meetings', $recordId, $earlier, $version);

        $secondCount = $this->purge($recordId);

        $this->assertSame([1, 1], [$firstCount, $secondCount]);
        $dues = Upload::query()->orderBy('id')->get()
            ->map(fn (Upload $upload) => [$upload->mime, $upload->purge_due_on?->toDateString()]);
        $this->assertSame([['audio/mpeg', '2026-10-20'], ['audio/x-wav', '2026-10-19']], $dues->all());
        $thirdCount = $this->purge($recordId);
        $this->assertSame(0, $thirdCount);
    }

    public function test_the_attachment_pane_reads_a_purged_row_with_its_due_date_and_no_preview(): void
    {
        $recordId = $this->meeting('2026-10-20', [$this->uploadedFile('a.mp3', 'mp3'), $this->uploadedFile('b.pdf')]);

        $this->purge($recordId);

        $items = app(RecordAttachmentQuery::class)->forRecord($recordId, 10)['items'];
        $this->assertSame(
            [['b.pdf', true, null], ['a.mp3', false, '2026-10-20']],
            array_map(fn (array $item) => [$item['name'], $item['previewable'], $item['purgeDueOn']], $items),
        );
        $this->assertSame(2, app(RecordAttachmentQuery::class)->countFor($recordId));
    }

    public function test_a_record_with_an_owner_is_purged_too(): void
    {
        $recordId = $this->meeting('2026-10-20', [$this->uploadedFile('a.wav', 'wav')]);
        $this->registerRecordHolds([$recordId => 'workflow-case:1']);

        $this->assertSame(1, $this->purge($recordId));

        $uploadId = RecordAttachment::query()->where('record_id', $recordId)->sole()->upload_id;
        $this->assertNotNull(Upload::query()->findOrFail($uploadId)->purged_at);
    }

    public function test_a_purged_record_can_be_deleted_with_its_rows_and_revisions(): void
    {
        $recordId = $this->meeting('2026-10-20', [$this->uploadedFile('a.mp3', 'mp3')]);
        $this->attachAndDetach($recordId, $this->uploadedFile('b.mp4', 'mp4'));
        $this->purge($recordId);

        app(DeleteRecord::class)->execute($this->contextFor(), 'meetings', $recordId);

        $this->assertSame(0, Upload::query()->count());
        $this->assertSame(0, RecordAttachment::query()->count());
        $this->assertSame(0, RecordRevision::query()->where('record_id', $recordId)->count());
    }

    public function test_a_record_whose_due_date_was_extended_after_it_was_chosen_is_not_purged(): void
    {
        $recordId = $this->meeting('2026-10-20', [$this->uploadedFile('a.mp3', 'mp3')]);
        $base = CarbonImmutable::parse('2026-10-21 03:00:00 UTC');
        $chosen = app(RecordDueQuery::class)->idsBefore('meetings', 'purge_on', $base);
        $version = Record::query()->findOrFail($recordId)->version();
        $extended = new RecordInput(['purge_on' => '2026-10-21']);
        app(UpdateRecord::class)->execute($this->contextFor(), 'meetings', $recordId, $extended, $version);

        $this->assertSame([$recordId], $chosen);
        $this->assertSame(0, $this->purge($recordId));
        $this->assertSame(0, Upload::query()->whereNotNull('purged_at')->count());
        $this->assertSame(1, $this->storedFileCount());
    }

    public function test_a_record_without_a_due_date_is_not_purged(): void
    {
        $recordId = $this->meeting(null, [$this->uploadedFile('a.mp3', 'mp3')]);

        $this->assertSame(0, $this->purge($recordId));
        $this->assertSame(0, Upload::query()->whereNotNull('purged_at')->count());
    }

    public function test_the_type_prefix_decides_not_the_format_table(): void
    {
        $recordId = $this->meeting('2026-10-20');
        $moduleId = $this->meetings->id;

        foreach (['video/quicktime', 'audio/ogg', 'application/pdf', 'image/png'] as $mime) {
            $upload = Upload::factory()->create(['mime' => $mime]);
            RecordAttachment::factory()->create([
                'record_module_id' => $moduleId, 'record_id' => $recordId,
                'upload_id' => $upload->id, 'name' => 'x.bin',
            ]);
        }

        $this->assertSame(2, $this->purge($recordId));
        $this->assertSame(
            ['audio/ogg', 'video/quicktime'],
            Upload::query()->whereNotNull('purged_at')->orderBy('mime')->pluck('mime')->all(),
        );
    }
}
