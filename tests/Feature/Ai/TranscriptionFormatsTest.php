<?php

namespace Tests\Feature\Ai;

use App\Queries\Ai\Api\TranscriptionFormats;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 文字起こしが受ける音声の形式(docs の 設計/業務ツール/会議/文字起こしと議事録.md の 4.3): 拡張子は mp3・m4a・wav(大文字小文字を区別しない)、
 * 型は添付の対応表のその行の型(写さない)。動画の拡張子と、音声の拡張子でも行に当たらない型は受けない。
 */
final class TranscriptionFormatsTest extends TestCase
{
    public function test_the_extensions_and_the_mimes_come_from_the_attachment_formats(): void
    {
        $formats = app(TranscriptionFormats::class);

        $this->assertSame(['mp3', 'm4a', 'wav'], $formats->extensions());
        $this->assertSame(
            ['audio/mpeg', 'audio/x-m4a', 'audio/mp4', 'video/mp4', 'audio/x-wav', 'audio/wav'],
            $formats->mimes(),
        );
    }

    /**
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function names(): array
    {
        return [
            'mp3' => ['定例.mp3', 'audio/mpeg', true, true],
            '大文字の M4A' => ['定例.M4A', 'audio/mp4', true, true],
            'wav' => ['a.b.wav', 'audio/x-wav', true, true],
            '音声の拡張子で行に当たらない型' => ['定例.mp3', 'audio/x-wav', true, false],
            '動画' => ['定例.mp4', 'audio/mp4', false, false],
            'テキスト' => ['定例.txt', 'text/plain', false, false],
            '拡張子なし' => ['mp3', 'audio/mpeg', false, false],
        ];
    }

    #[DataProvider('names')]
    public function test_a_name_and_a_mime_are_audio_only_on_the_row_of_an_audio_extension(
        string $name,
        string $mime,
        bool $audioName,
        bool $accepted,
    ): void {
        $formats = app(TranscriptionFormats::class);

        $this->assertSame([$audioName, $accepted], [$formats->isAudioName($name), $formats->accepts($name, $mime)]);
    }
}
