<?php

namespace Tests\Feature\Queries\Upload;

use App\Models\Upload\Upload;
use App\Queries\Upload\Api\UploadQuery;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 実体のパスの読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節・4 節): 台帳の行の path の、ディスク local の
 * 絶対パス。行が無い・期限で実体を消した行は無い(null)。
 */
final class UploadQueryTest extends TransactionalTestCase
{
    use BuildsUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
    }

    public function test_the_path_is_the_absolute_path_on_the_local_disk(): void
    {
        $upload = Upload::factory()->create(['path' => 'uploads/abc.m4a']);

        $path = app(UploadQuery::class)->pathOf($upload->id);

        $this->assertSame(Storage::disk('local')->path('uploads/abc.m4a'), $path);
        $this->assertStringStartsWith('/', $path);
    }

    public function test_a_missing_row_has_no_path(): void
    {
        $id = Upload::factory()->create()->id;

        $this->assertNull(app(UploadQuery::class)->pathOf($id + 1000));
    }

    public function test_a_purged_row_has_no_path(): void
    {
        $upload = Upload::factory()->purged()->create();

        $this->assertNull(app(UploadQuery::class)->pathOf($upload->id));
    }
}
