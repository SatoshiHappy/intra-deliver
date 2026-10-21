<?php

namespace Tests\Feature\Actions\Upload;

use App\Actions\Upload\Api\PurgeUploads;
use App\Actions\Upload\Api\StoreUploads;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use App\Queries\Upload\Api\UploadLimits;
use App\Queries\Upload\Api\UploadQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 実体だけを消す口(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節・6 節の 4、検査 = 8 節の「消す」「合計」)。
 */
final class PurgeUploadsTest extends TransactionalTestCase
{
    use BuildsUploads;
    use CatchesValidationErrors;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-21 03:00:00 UTC');
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_the_rows_stay_with_the_purge_columns_and_the_files_go_after_the_commit(): void
    {
        [$first, $second] = $this->store(['a.mp3', 'b.mp4'], ['mp3', 'mp4']);
        $paths = Upload::query()->whereKey([$first, $second])->orderBy('id')->pluck('path')->all();

        DB::transaction(function () use ($first, $second) {
            app(PurgeUploads::class)->execute([$first, $second, $second + 1000], CarbonImmutable::parse('2026-10-20'));
            $this->assertSame(2, $this->storedFileCount(), 'コミットの前は実体が残る');
        });

        $this->assertSame(0, $this->storedFileCount());
        Storage::disk('local')->assertMissing($paths);

        foreach (Upload::query()->whereKey([$first, $second])->get() as $upload) {
            $this->assertSame('2026-10-21 03:00:00', $upload->purged_at?->utc()->toDateTimeString());
            $this->assertSame('2026-10-20', $upload->purge_due_on?->toDateString());
        }
    }

    public function test_the_due_date_is_written_as_the_date_part_whatever_the_time_zone(): void
    {
        [$id] = $this->store(['a.mp3'], ['mp3']);

        app(PurgeUploads::class)->execute([$id], CarbonImmutable::parse('2026-10-05 00:00:00', 'Asia/Tokyo'));

        $this->assertSame('2026-10-05', Upload::query()->findOrFail($id)->purge_due_on?->toDateString());
    }

    public function test_a_purged_row_is_out_of_the_total_and_has_no_path_to_read(): void
    {
        [$id] = $this->store(['a.mp3'], ['mp3']);
        $size = Upload::query()->findOrFail($id)->size;
        $this->limitUploads(1_000, 1_000, $size + 1);
        $other = $this->uploadedFile('b.pdf');
        $errors = $this->validationErrors(fn () => app(StoreUploads::class)->execute(null, [$other]));
        $this->assertSame(['attachments' => UploadLimits::TOTAL_EXCEEDED], $errors);

        app(PurgeUploads::class)->execute([$id], CarbonImmutable::parse('2026-10-20'));

        $this->assertCount(1, app(StoreUploads::class)->execute(null, [$other]));
        $this->assertNull(app(UploadQuery::class)->pathOf($id));
        $this->assertSame('2026-10-20', app(UploadQuery::class)->find([$id])[$id]->purgeDueOn);
    }

    public function test_a_row_that_an_owner_points_at_can_be_purged_and_the_owner_row_stays(): void
    {
        [$id] = $this->store(['a.mp4'], ['mp4']);
        $record = Record::factory()->create();
        $attachment = RecordAttachment::factory()->create([
            'record_module_id' => $record->record_module_id, 'record_id' => $record->id, 'upload_id' => $id,
        ]);

        app(PurgeUploads::class)->execute([$id], CarbonImmutable::parse('2026-10-20'));

        $this->assertNotNull(Upload::query()->findOrFail($id)->purged_at);
        $this->assertSame($id, $attachment->refresh()->upload_id);
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_calling_it_again_changes_nothing_and_missing_ids_are_ignored(): void
    {
        [$id] = $this->store(['a.mp3'], ['mp3']);
        app(PurgeUploads::class)->execute([$id], CarbonImmutable::parse('2026-10-20'));
        CarbonImmutable::setTestNow('2026-10-22 03:00:00 UTC');

        app(PurgeUploads::class)->execute([$id, $id + 1000], CarbonImmutable::parse('2026-10-21'));
        app(PurgeUploads::class)->execute([], CarbonImmutable::parse('2026-10-21'));

        $upload = Upload::query()->findOrFail($id);
        $this->assertSame('2026-10-21 03:00:00', $upload->purged_at?->utc()->toDateTimeString());
        $this->assertSame('2026-10-20', $upload->purge_due_on?->toDateString());
    }

    public function test_it_returns_the_number_of_rows_it_actually_purged(): void
    {
        [$first, $second] = $this->store(['a.mp3', 'b.mp4'], ['mp3', 'mp4']);
        $dueOn = CarbonImmutable::parse('2026-10-20');
        $purge = app(PurgeUploads::class);

        $this->assertSame(1, $purge->execute([$first, $first + 1000], $dueOn));
        $this->assertSame(1, $purge->execute([$first, $second], $dueOn));
        $this->assertSame(0, $purge->execute([$first, $second, $second + 1000], $dueOn));
        $this->assertSame(0, $purge->execute([], $dueOn));
    }

    public function test_a_rolled_back_purge_keeps_the_row_and_the_file(): void
    {
        [$id] = $this->store(['a.mp3'], ['mp3']);

        try {
            DB::transaction(function () use ($id) {
                app(PurgeUploads::class)->execute([$id], CarbonImmutable::parse('2026-10-20'));

                throw new RuntimeException('呼び手の失敗');
            });
        } catch (RuntimeException) {
        }

        $this->assertNull(Upload::query()->findOrFail($id)->purged_at);
        $this->assertSame(1, $this->storedFileCount());
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $kinds
     * @return list<int>
     */
    private function store(array $names, array $kinds): array
    {
        $files = array_map(fn (string $name, string $kind) => $this->uploadedFile($name, $kind), $names, $kinds);

        return app(StoreUploads::class)->execute(null, $files);
    }
}
