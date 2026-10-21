<?php

namespace Tests\Support;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * 添付の実体を使うテストの手助け(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 8 節)。
 * 実体のディスク local を Storage::fake の一時の置き場に替え、テストの終わりに置き場ごと消す(作業木の storage/ に残さない)。
 * 見本の中身は形式ごとに最小のもの(PHP の fileinfo がその型と推す先頭のバイト)、Office の見本は tests/Fixtures/Upload の包み。
 */
trait BuildsUploads
{
    /** 1 ファイル・1 回・合計の上限を小さくした値(大きいファイルをテストで作らない) */
    private const SMALL_LIMIT = 1_000;

    protected function fakeUploadDisk(): void
    {
        $root = Storage::fake('local')->path('');
        // 自分の置き場(トークンつき)だけを消す。親の disks は並べて走るほかのテストのプロセスと共有なので触れない
        $this->beforeApplicationDestroyed(fn () => (new Filesystem)->deleteDirectory($root));
    }

    protected function limitUploads(
        int $file = self::SMALL_LIMIT,
        int $request = self::SMALL_LIMIT,
        int $total = 1_000_000,
    ): void {
        config([
            'uploads.max_file_bytes' => $file,
            'uploads.max_request_bytes' => $request,
            'uploads.max_total_bytes' => $total,
        ]);
    }

    /**
     * 形式の見本(キー = 拡張子)。
     */
    protected function sampleContent(string $kind): string
    {
        $office = ['docx', 'xlsx', 'pptx'];

        if (MediaSamples::has($kind)) {
            return MediaSamples::bytes($kind);
        }

        if (in_array($kind, $office, true)) {
            return (string) file_get_contents(base_path("tests/Fixtures/Upload/sample.{$kind}"));
        }

        return [
            'pdf' => "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
            'png' => (string) base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==',
            ),
            'jpeg' => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9",
            'gif' => "GIF89a\x01\x00\x01\x00\x00\x00\x00;",
            'txt' => "hello\n",
            'csv' => "name,qty\napple,3\n",
            'md' => "# 見出し\n\n本文です。\n",
            'zip' => "PK\x05\x06".str_repeat("\x00", 18),
        ][$kind];
    }

    /**
     * 送られたファイル(名前は送られたまま。中身は見本)。
     */
    protected function uploadedFile(string $name, string $kind = 'pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $this->sampleContent($kind));
    }

    /**
     * 大きさが bytes ちょうどの PDF(先頭は PDF の見本、残りを空白で埋める)。
     */
    protected function pdfOfSize(string $name, int $bytes): UploadedFile
    {
        $head = $this->sampleContent('pdf');

        return UploadedFile::fake()->createWithContent($name, $head.str_repeat(' ', $bytes - strlen($head)));
    }

    /**
     * 実体のディスクに置かれたファイル(uploads/ の下)の数。
     */
    protected function storedFileCount(): int
    {
        return count(Storage::disk('local')->allFiles('uploads'));
    }
}
