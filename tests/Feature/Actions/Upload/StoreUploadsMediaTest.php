<?php

namespace Tests\Feature\Actions\Upload;

use App\Actions\Upload\Api\StoreUploads;
use App\Models\Upload\Upload;
use App\Queries\Upload\Api\UploadLimits;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mime\MimeTypes;
use Tests\Support\BuildsUploads;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\MediaSamples;
use Tests\Support\TransactionalTestCase;

/**
 * 音声・動画の形式(docs の 設計/業務データの入れ物/レコードの共通機能/添付ファイル/README.md の 3 節の表、検査 = 8 節の「形式」)。
 * 受ける型は見本を実際に流して決めた(経過の「形式の見本の実測」)。見本はテストの中で組んだ最小のバイト列。
 */
final class StoreUploadsMediaTest extends TransactionalTestCase
{
    use BuildsUploads;
    use CatchesValidationErrors;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeUploadDisk();
        $this->limitUploads();
    }

    public function test_the_samples_are_guessed_as_the_types_that_the_table_was_decided_from(): void
    {
        $types = MimeTypes::getDefault();

        foreach (MediaSamples::MIMES as $kind => $mime) {
            $path = tempnam(sys_get_temp_dir(), 'media');
            file_put_contents($path, MediaSamples::bytes($kind));

            try {
                $this->assertSame($mime, $types->guessMimeType($path), "見本 {$kind}");
            } finally {
                unlink($path);
            }
        }
    }

    /**
     * @return array<string, array{string, string}> 名前・見本の種類(推される型は MediaSamples::MIMES)
     */
    public static function acceptedMedia(): array
    {
        return [
            'mp3' => ['a.mp3', 'mp3'],
            'wav' => ['a.wav', 'wav'],
            'm4a' => ['a.m4a', 'm4a'],
            'm4a as audio/mp4' => ['a.m4a', 'm4a-mp4'],
            'm4a recorded as a video mp4 brand' => ['a.m4a', 'mp4'],
            'mp4' => ['a.mp4', 'mp4'],
            'mp4 with audio only' => ['a.mp4', 'mp4-audio'],
            'webm' => ['a.webm', 'webm'],
            'an upper case extension' => ['A.MP3', 'mp3'],
        ];
    }

    #[DataProvider('acceptedMedia')]
    public function test_each_audio_and_video_row_of_the_format_table_passes(string $name, string $kind): void
    {
        [$id] = app(StoreUploads::class)->execute(null, [$this->uploadedFile($name, $kind)]);

        $upload = Upload::query()->findOrFail($id);
        $this->assertSame(MediaSamples::MIMES[$kind], $upload->mime);
        Storage::disk('local')->assertExists($upload->path);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedMedia(): array
    {
        return [
            'an mp3 with the content of a png' => ['a.mp3', 'png'],
            'a wav with the content of an mp3' => ['a.wav', 'mp3'],
            'a webm with the content of an mp4' => ['a.webm', 'mp4'],
            'an mp4 with the content of a webm' => ['a.mp4', 'webm'],
            'an mp3 with the content of a wav' => ['a.mp3', 'wav'],
            'a pdf with the content of an mp3' => ['a.pdf', 'mp3'],
        ];
    }

    #[DataProvider('rejectedMedia')]
    public function test_a_content_out_of_the_row_of_its_extension_is_rejected(string $name, string $kind): void
    {
        $errors = $this->validationErrors(
            fn () => app(StoreUploads::class)->execute(null, [$this->uploadedFile($name, $kind)]),
        );

        $this->assertSame(['attachments.0' => UploadLimits::UNSUPPORTED_FORMAT], $errors);
        $this->assertSame(0, $this->storedFileCount());
    }

    public function test_the_extensions_without_a_row_are_rejected(): void
    {
        $errors = $this->validationErrors(fn () => app(StoreUploads::class)->execute(null, [
            $this->uploadedFile('a.mov', 'mp4'),
            $this->uploadedFile('a.ogg', 'mp3'),
            $this->uploadedFile('a.mkv', 'webm'),
        ]));

        $keys = ['attachments.0', 'attachments.1', 'attachments.2'];
        $this->assertSame(array_fill_keys($keys, UploadLimits::UNSUPPORTED_FORMAT), $errors);
    }
}
