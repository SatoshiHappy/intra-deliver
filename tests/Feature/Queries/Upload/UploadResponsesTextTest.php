<?php

namespace Tests\Feature\Queries\Upload;

use App\Actions\Upload\Api\StoreUploads;
use App\Models\Upload\Upload;
use App\Queries\Upload\Api\UploadResponses;
use App\Queries\Upload\TextCharset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\BuildsUploads;
use Tests\Support\TransactionalTestCase;

/**
 * テキスト・Markdown・CSV のプレビューの型と文字コード(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 4 節の
 * 「テキストのプレビュー」、検査 = 8 節の「応答」)。区切りより大きい見本は、台帳の行と実体を直接置く(中身から推す型の検査を通さない)。
 */
final class UploadResponsesTextTest extends TransactionalTestCase
{
    use BuildsUploads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function textFiles(): array
    {
        // md の名前を分けて書く(docs の参照の照合が拡張子 md の語を文書と読むため)
        return ['txt' => ['a.txt', 'txt'], 'md' => ['a.'.'md', 'md'], 'csv' => ['a.csv', 'csv']];
    }

    #[DataProvider('textFiles')]
    public function test_a_text_preview_is_inline_nosniff_and_plain_text(string $name, string $kind): void
    {
        $id = app(StoreUploads::class)->execute(null, [$this->uploadedFile($name, $kind)])[0];

        $response = $this->prepared($id);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('inline', $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_a_markdown_saved_as_text_markdown_previews_as_plain_text(): void
    {
        $response = $this->prepared($this->placed("# 見出し\n", 'text/markdown'));

        $this->assertSame('text/plain; charset=UTF-8', $response->headers->get('Content-Type'));
        $this->assertSame('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_a_text_download_keeps_the_saved_type_and_the_attachment(): void
    {
        $id = $this->placed("名前\n", 'text/csv');

        $response = app(UploadResponses::class)->download($id, 'a.csv');

        $this->assertSame('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function charsets(): array
    {
        $csv = "名前,数\nりんご,3\n";

        return [
            'utf-8' => [$csv, 'UTF-8'],
            'ascii only' => ["name,qty\napple,3\n", 'UTF-8'],
            'utf-8 with a BOM' => ["\xEF\xBB\xBF".$csv, 'UTF-8'],
            'cp932 (an Excel csv)' => [self::cp932($csv), 'Shift_JIS'],
            'neither utf-8 nor cp932' => ["abc\xFF\xFF\xFE", 'UTF-8'],
            'cp932 cut at the end of the file' => ["abc\x82", 'UTF-8'],
            'utf-8 cut at the end of the file' => ["abc\xE3", 'UTF-8'],
            'a lone half-width katakana lead in cp932' => [self::cp932("ｱｲｳ\n"), 'Shift_JIS'],
        ];
    }

    #[DataProvider('charsets')]
    public function test_the_charset_label_comes_from_the_content(string $bytes, string $label): void
    {
        $response = $this->prepared($this->placed($bytes, 'text/csv'));

        $this->assertSame("text/plain; charset={$label}", $response->headers->get('Content-Type'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function bigSamples(): array
    {
        $chunk = TextCharset::CHUNK_BYTES;

        return [
            'utf-8 cut after 1 byte of a 3-byte character' => [str_repeat('a', $chunk - 1).'あい', 'UTF-8'],
            'utf-8 cut after 2 bytes of a 3-byte character' => [str_repeat('a', $chunk - 2).'あい', 'UTF-8'],
            'utf-8 cut after 3 bytes of a 4-byte character' => [str_repeat('a', $chunk - 3)."\u{1F600}a", 'UTF-8'],
            'utf-8 over several chunks' => [str_repeat('あ', $chunk), 'UTF-8'],
            'cp932 cut inside a 2-byte character' => [str_repeat('a', $chunk - 1).self::cp932('あい'), 'Shift_JIS'],
            'cp932 over several chunks' => [str_repeat(self::cp932('あ'), $chunk), 'Shift_JIS'],
            'ascii first chunk then cp932' => [str_repeat('a', $chunk).self::cp932('あ'), 'Shift_JIS'],
            'ascii first chunk then bytes of neither' => [str_repeat('a', $chunk)."\xFF\xFF", 'UTF-8'],
            'cp932 ends with a lone lead after a chunk' => [str_repeat('a', $chunk).self::cp932('あ')."\x82", 'UTF-8'],
        ];
    }

    #[DataProvider('bigSamples')]
    public function test_a_sample_larger_than_the_read_chunk_is_judged_as_a_whole(string $bytes, string $label): void
    {
        $response = $this->prepared($this->placed($bytes, 'text/csv'));

        $this->assertSame("text/plain; charset={$label}", $response->headers->get('Content-Type'));
    }

    private static function cp932(string $utf8): string
    {
        return mb_convert_encoding($utf8, 'CP932', 'UTF-8');
    }

    /**
     * 台帳の行と実体を直接置く(保存した型 = mime)。
     */
    private function placed(string $bytes, string $mime): int
    {
        $path = 'uploads/'.Str::random(40).'.txt';
        Storage::disk('local')->put($path, $bytes);

        return Upload::factory()->create(['path' => $path, 'mime' => $mime, 'size' => strlen($bytes)])->id;
    }

    private function prepared(int $uploadId): BinaryFileResponse
    {
        return app(UploadResponses::class)->preview($uploadId)->prepare(Request::create('/'));
    }
}
