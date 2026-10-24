<?php

namespace Tests\Feature\Actions\Upload;

use App\Actions\Upload\Api\StoreUploads;
use App\Models\Upload\Upload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use League\Flysystem\UnableToDeleteFile;
use RuntimeException;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 巻き戻しの後に実体を消せなかったとき(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節の「後から消す実体の削除」、
 * 検査 = 8 節の「巻き戻し」): 削除の失敗は報告し、呼び手には元の例外がそのまま届く。
 */
final class StoreUploadsRollbackReportTest extends TransactionalTestCase
{
    use BuildsUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_a_stuck_file_after_the_rollback_is_reported_and_the_caller_gets_the_original_exception(): void
    {
        Exceptions::fake();
        $this->makeUploadsUndeletable(fn (): bool => true);
        $message = null;

        try {
            DB::transaction(function () {
                app(StoreUploads::class)->execute(null, [$this->uploadedFile('a.pdf')]);

                throw new RuntimeException('呼び手の失敗');
            });
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
        }

        $this->assertSame('呼び手の失敗', $message);
        Exceptions::assertReported(UnableToDeleteFile::class);
        $this->assertSame(0, Upload::query()->count());
        $this->assertSame(1, $this->storedFileCount());
    }
}
