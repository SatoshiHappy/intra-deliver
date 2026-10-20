<?php

namespace Tests\Feature\Queries\Upload;

use App\Actions\Upload\Api\StoreUploads;
use App\Models\Upload\Upload;
use App\Queries\Upload\Api\UploadQuery;
use App\Queries\Upload\Api\UploadResponses;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * 実体の応答と台帳の読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 2 節・4 節、検査 = 8 節の「応答」)。
 */
final class UploadResponsesTest extends TransactionalTestCase
{
    use BuildsUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_a_download_is_an_attachment_with_the_saved_type_nosniff_and_a_utf8_name(): void
    {
        $id = $this->upload('a.pdf');

        $response = $this->prepared(app(UploadResponses::class)->download($id, '見積書 100%.pdf'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertSame(
            "attachment; filename=\" 100.pdf\"; filename*=utf-8''%E8%A6%8B%E7%A9%8D%E6%9B%B8%20100%25.pdf",
            $response->headers->get('Content-Disposition'),
        );
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function previewable(): array
    {
        return ['png' => ['a.png', 'png'], 'jpeg' => ['a.jpg', 'jpeg'], 'pdf' => ['a.pdf', 'pdf']];
    }

    #[DataProvider('previewable')]
    public function test_a_preview_of_png_jpeg_and_pdf_is_inline(string $name, string $kind): void
    {
        $response = app(UploadResponses::class)->preview($this->upload($name, $kind));

        $this->assertSame('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_preview_of_other_types_is_not_found(): void
    {
        $id = $this->upload('a.txt', 'txt');

        $this->expectException(ModelNotFoundException::class);

        app(UploadResponses::class)->preview($id);
    }

    public function test_a_purged_row_a_missing_file_and_a_missing_row_are_not_found(): void
    {
        $missingFile = $this->upload('a.pdf');
        Storage::disk('local')->delete(Upload::query()->findOrFail($missingFile)->path);
        $purged = $this->upload('b.pdf');
        Upload::query()->whereKey($purged)->update(['purged_at' => now(), 'purge_due_on' => today()]);
        $responses = app(UploadResponses::class);

        foreach ([$missingFile, $purged, $purged + 1000] as $id) {
            foreach ([fn () => $responses->download($id, 'a.pdf'), fn () => $responses->preview($id)] as $open) {
                try {
                    $open();
                    $this->fail("{$id} が 404 にならなかった");
                } catch (ModelNotFoundException) {
                    $this->addToAssertionCount(1);
                }
            }
        }
    }

    public function test_a_range_request_gets_206_with_the_content_range_and_out_of_range_gets_416(): void
    {
        $id = $this->upload('a.pdf');
        $size = strlen($this->sampleContent('pdf'));
        $responses = app(UploadResponses::class);

        $partial = $this->prepared($responses->download($id, 'a.pdf'), 'bytes=0-9');
        $outside = $this->prepared($responses->download($id, 'a.pdf'), 'bytes=1000-2000');

        $this->assertSame(206, $partial->getStatusCode());
        $this->assertSame("bytes 0-9/{$size}", $partial->headers->get('Content-Range'));
        $this->assertSame('10', $partial->headers->get('Content-Length'));
        $this->assertSame(416, $outside->getStatusCode());
        $this->assertSame("bytes */{$size}", $outside->headers->get('Content-Range'));
    }

    public function test_the_query_returns_the_rows_with_the_due_date_of_a_purge(): void
    {
        $kept = $this->upload('a.pdf');
        $purged = Upload::factory()->create([
            'size' => 7, 'mime' => 'audio/mpeg', 'purged_at' => now(), 'purge_due_on' => '2026-10-01',
        ]);

        $files = app(UploadQuery::class)->find([$kept, $purged->id, $purged->id + 1000]);

        $this->assertSame([$kept, $purged->id], array_keys($files));
        $this->assertSame(['application/pdf', null], [$files[$kept]->mime, $files[$kept]->purgeDueOn]);
        $file = $files[$purged->id];
        $this->assertSame([7, 'audio/mpeg', '2026-10-01'], [$file->size, $file->mime, $file->purgeDueOn]);
    }

    private function upload(string $name, string $kind = 'pdf'): int
    {
        return app(StoreUploads::class)->execute(null, [$this->uploadedFile($name, $kind)])[0];
    }

    private function prepared(BinaryFileResponse $response, ?string $range = null): BinaryFileResponse
    {
        $request = Request::create('/', 'GET', server: $range === null ? [] : ['HTTP_RANGE' => $range]);

        return $response->prepare($request);
    }
}
