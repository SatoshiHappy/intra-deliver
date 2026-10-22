<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\AudioChunk;
use App\Actions\Ai\AudioConversionFailed;
use App\Actions\Ai\AudioConverter;
use App\Actions\Ai\AudioUnreadable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 音声の長さの測りと変換と区切り(docs の 設計/AI/実行と費用.md 4.2)。テストの音声は ffmpeg の lavfi(正弦波と無音)でテストの中で作る。
 * 区切りの長さ 10 秒・無音を探す幅 4 秒で、境が目標の前の幅の中の無音の中ほどに寄る・無音が無ければ目標で切れる・どの区切りも区切りの長さ以下・
 * 区切りの長さの和が音声の長さ、全体が無音の区切りと終わりの無音の長さ、変換の形(モノラル・16kHz・16kbps)、測れない音声と時間切れを見る。
 */
final class AudioConverterTest extends TransactionalTestCase
{
    /** 長さと時刻の許す差(秒。符号化の端数と無音の検出の丸め) */
    private const SECONDS_DELTA = 0.1;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/audio-converter-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
        config(['ai_runs.transcription.silence_window_seconds' => 4]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function formats(): array
    {
        return ['wav' => ['wav'], 'mp3' => ['mp3'], 'm4a' => ['m4a']];
    }

    #[DataProvider('formats')]
    public function test_measures_the_length_of_an_audio_file(string $extension): void
    {
        $path = $this->audio('sin(2*PI*440*t)', 5, $extension);

        $this->assertEqualsWithDelta(5.0, app(AudioConverter::class)->measure($path), self::SECONDS_DELTA);
    }

    public function test_a_broken_file_and_a_file_without_an_audio_stream_cannot_be_measured(): void
    {
        $broken = "{$this->directory}/broken.mp3";
        File::put($broken, 'これは音声ではない');
        $image = "{$this->directory}/image.png";
        $this->ffmpeg(['-f', 'lavfi', '-i', 'color=c=black:s=16x16', '-frames:v', '1', $image]);

        foreach ([$broken, $image, "{$this->directory}/missing.wav"] as $path) {
            try {
                app(AudioConverter::class)->measure($path);
                $this->fail("{$path} が測れてしまった");
            } catch (AudioUnreadable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_chunks_are_mono_16khz_16kbps_mp3(): void
    {
        $chunks = $this->split($this->audio('sin(2*PI*440*t)', 5), 10);

        $this->assertCount(1, $chunks);
        $probe = Process::run([
            'ffprobe', '-v', 'error', '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_name,channels,sample_rate,bit_rate', '-of', 'json', $chunks[0]->path,
        ]);
        $stream = json_decode($probe->output(), true)['streams'][0];
        $actual = [$stream['codec_name'], $stream['channels'], $stream['sample_rate'], $stream['bit_rate']];
        $this->assertSame(['mp3', 1, '16000', '16000'], $actual);
    }

    /** 無音 7〜9 秒・15〜17 秒の 25 秒: 目標 10 秒の前の幅 6〜10 秒の無音の中ほど 8 秒、次は目標 18 秒の前の 16 秒で切れる */
    public function test_the_boundaries_move_to_the_middle_of_the_last_silence_before_the_target(): void
    {
        $chunks = $this->split($this->audio('if(between(t,7,9)+between(t,15,17),0,sin(2*PI*440*t))', 25), 10);

        $this->assertBounds([[0, 8], [8, 16], [16, 25]], $chunks);
        $this->assertTrailingSilences([1, 1, 0], $chunks);
        $this->assertSame([false, false, false], array_map(fn (AudioChunk $chunk): bool => $chunk->isSilent, $chunks));
    }

    public function test_without_silence_the_audio_is_cut_at_the_target_times(): void
    {
        $chunks = $this->split($this->audio('sin(2*PI*440*t)', 25), 10);

        $this->assertBounds([[0, 10], [10, 20], [20, 25]], $chunks);
        $this->assertTrailingSilences([0, 0, 0], $chunks);
    }

    /** 音は 0〜10 秒だけ: 最初は目標 10 秒で切れ(無音の中ほど 17.5 秒は幅の外)、次は 17.5 秒に寄る。後の 2 つは全体が無音 */
    public function test_the_chunks_tell_the_trailing_silence_and_whether_they_are_silent(): void
    {
        $chunks = $this->split($this->audio('if(lt(t,10),sin(2*PI*440*t),0)', 25), 10);

        $this->assertBounds([[0, 10], [10, 17.5], [17.5, 25]], $chunks);
        $this->assertTrailingSilences([0, 7.5, 7.5], $chunks);
        $this->assertSame([false, true, true], array_map(fn (AudioChunk $chunk): bool => $chunk->isSilent, $chunks));
    }

    /** 書き手の無い名前つきパイプは開いたまま終わらないので、測りも区切りも時間の上限(1 秒)で変換の失敗になる */
    public function test_a_process_over_the_time_limit_is_a_conversion_failure(): void
    {
        config(['ai_runs.transcription.conversion_timeout_seconds' => 1]);
        $pipe = "{$this->directory}/pipe.wav";
        $this->assertTrue(posix_mkfifo($pipe, 0600));

        foreach ([fn () => app(AudioConverter::class)->measure($pipe), fn () => $this->split($pipe, 10)] as $call) {
            try {
                $call();
                $this->fail('時間の上限を超えたのに変換の失敗にならなかった');
            } catch (AudioConversionFailed $exception) {
                $this->assertNotInstanceOf(AudioUnreadable::class, $exception);
                $this->assertStringContainsString('時間の上限', $exception->getMessage());
            }
        }
    }

    /**
     * lavfi の式の音声(16kHz・モノラル)を作る。
     */
    private function audio(string $expression, int $seconds, string $extension = 'wav'): string
    {
        $path = "{$this->directory}/source-".bin2hex(random_bytes(4)).".{$extension}";
        $this->ffmpeg(['-f', 'lavfi', '-i', "aevalsrc='{$expression}':s=16000:d={$seconds}", $path]);

        return $path;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function ffmpeg(array $arguments): void
    {
        $result = Process::run(['ffmpeg', '-hide_banner', '-nostdin', '-v', 'error', '-y', ...$arguments]);
        $this->assertTrue($result->successful(), $result->errorOutput());
    }

    /**
     * @return list<AudioChunk>
     */
    private function split(string $path, int $chunkSeconds): array
    {
        return app(AudioConverter::class)->split($path, "{$this->directory}/chunks", $chunkSeconds);
    }

    /**
     * 区切りの始まりと終わり。始まりは前の区切りの終わりと同じで、長さの和が音声の長さになり、どの区切りも 10 秒以下。
     *
     * @param  list<array{int|float, int|float}>  $expected
     * @param  list<AudioChunk>  $chunks
     */
    private function assertBounds(array $expected, array $chunks): void
    {
        $this->assertCount(count($expected), $chunks);
        $end = 0.0;

        foreach ($chunks as $index => $chunk) {
            $this->assertFileExists($chunk->path);
            $this->assertEqualsWithDelta($end, $chunk->startSeconds, 1e-9);
            $this->assertEqualsWithDelta($expected[$index][0], $chunk->startSeconds, self::SECONDS_DELTA);
            $chunkEnd = $chunk->startSeconds + $chunk->lengthSeconds;
            $this->assertEqualsWithDelta($expected[$index][1], $chunkEnd, self::SECONDS_DELTA);
            $this->assertLessThanOrEqual(10.0, $chunk->lengthSeconds);
            $end = $chunkEnd;
        }

        $this->assertEqualsWithDelta($expected[count($expected) - 1][1], $end, self::SECONDS_DELTA);
    }

    /**
     * @param  list<int|float>  $expected
     * @param  list<AudioChunk>  $chunks
     */
    private function assertTrailingSilences(array $expected, array $chunks): void
    {
        foreach ($chunks as $index => $chunk) {
            $this->assertEqualsWithDelta($expected[$index], $chunk->trailingSilenceSeconds, self::SECONDS_DELTA);
        }
    }
}
