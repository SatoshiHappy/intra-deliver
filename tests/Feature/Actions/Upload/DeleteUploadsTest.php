<?php

namespace Tests\Feature\Actions\Upload;

use App\Actions\Upload\Api\DeleteUploads;
use App\Actions\Upload\Api\StoreUploads;
use App\Models\Records\RecordAttachment;
use App\Models\Upload\Upload;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 台帳の行と実体を消す口(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節・6 節、検査 = 8 節の「消す」)。
 */
final class DeleteUploadsTest extends TransactionalTestCase
{
    use BuildsUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_it_deletes_the_rows_and_the_files_after_the_commit_and_ignores_missing_ids(): void
    {
        $files = [$this->uploadedFile('a.pdf'), $this->uploadedFile('b.pdf')];
        [$first, $second] = app(StoreUploads::class)->execute(null, $files);
        $paths = Upload::query()->whereKey([$first, $second])->pluck('path')->all();

        DB::transaction(function () use ($first, $second) {
            app(DeleteUploads::class)->execute([$first, $second, $second + 1000]);
            $this->assertSame(2, $this->storedFileCount(), 'コミットの前は実体が残る');
        });

        $this->assertSame(0, Upload::query()->whereKey([$first, $second])->count());
        Storage::disk('local')->assertMissing($paths);
    }

    public function test_a_rolled_back_delete_keeps_the_rows_and_the_files(): void
    {
        [$id] = app(StoreUploads::class)->execute(null, [$this->uploadedFile('a.pdf')]);

        try {
            DB::transaction(function () use ($id) {
                app(DeleteUploads::class)->execute([$id]);

                throw new RuntimeException('呼び手の失敗');
            });
        } catch (RuntimeException) {
        }

        $this->assertTrue(Upload::query()->whereKey($id)->exists());
        $this->assertSame(1, $this->storedFileCount());
    }

    public function test_an_upload_pointed_by_an_owner_row_is_a_foreign_key_violation(): void
    {
        $attachment = RecordAttachment::factory()->create();

        try {
            app(DeleteUploads::class)->execute([$attachment->upload_id]);
            $this->fail('外部キーの例外にならなかった');
        } catch (QueryException $exception) {
            $this->assertContains($exception->getCode(), ['23001', '23503']);
        }

        $this->assertTrue(Upload::query()->whereKey($attachment->upload_id)->exists());
    }
}
