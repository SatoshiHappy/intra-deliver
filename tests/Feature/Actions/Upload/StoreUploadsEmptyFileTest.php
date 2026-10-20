<?php

namespace Tests\Feature\Actions\Upload;

use App\Actions\Upload\Api\StoreUploads;
use App\Models\Upload\Upload;
use App\Queries\Upload\Api\UploadLimits;
use Illuminate\Http\UploadedFile;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\TransactionalTestCase;

/**
 * 0 バイトのファイルは形式の誤りでなく空のファイルの誤りにする(確かめの順 = 大きさ → 空 → 名前 → 形式。ファイルごとの誤りはまとめて返す。
 * docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 3 節)。
 */
final class StoreUploadsEmptyFileTest extends TransactionalTestCase
{
    use BuildsUploads;
    use CatchesValidationErrors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_an_empty_file_is_rejected_as_empty(): void
    {
        $empty = UploadedFile::fake()->createWithContent('memo.txt', '');

        $errors = $this->validationErrors(fn () => app(StoreUploads::class)->execute(null, [$empty]));

        $this->assertSame(['attachments.0' => UploadLimits::EMPTY_FILE], $errors);
        $this->assertSame(0, Upload::query()->count());
    }

    public function test_an_empty_file_and_a_wrong_name_are_both_returned(): void
    {
        $files = [UploadedFile::fake()->createWithContent('memo.txt', ''), $this->uploadedFile('a.exe')];

        $errors = $this->validationErrors(fn () => app(StoreUploads::class)->execute(null, $files));

        $this->assertSame(
            ['attachments.0' => UploadLimits::EMPTY_FILE, 'attachments.1' => UploadLimits::UNSUPPORTED_FORMAT],
            $errors,
        );
    }
}
