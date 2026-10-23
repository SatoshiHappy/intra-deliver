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
 * 区切りの長さ C = 10 秒・無音を探す幅 W = 2 秒(C > 3W)で、区切りの数を先に決めて等しく割った目標の前の幅の中の無音の中ほどに境が寄る・
 * 無音が無ければ目標で切れる・どの区切りも C 以下・区切りの長さの和が音声の長さ・終わりの無音の長さ、変換の形(モノラル・16kHz・16kbps)、
 * 測れない音声と時間切れを見る(式の正本 = 設計/AI/実行と費用.md 4.2)。
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
        config(['ai_runs.transcription.silence_window_seconds' => 2]);
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

    /**
     * 長さは入れ物の見出しで測れても、先頭を復号できない音声(ffmpeg に decoder の無い符号化・中身の壊れた音声)は、依頼の時点に
     * 読み取れない音声になる(費用を予約する前に断る)。ffprobe は通り、復号の ffmpeg だけが落ちる形を偽物で作る。
     */
    public function test_an_audio_whose_head_cannot_be_decoded_cannot_be_measured(): void
    {
        $path = $this->audio('sin(2*PI*440*t)', 5);
        Process::fake([
            "'ffprobe'*" => Process::result('{"streams": [{"codec_type": "audio"}], "format": {"duration": "5.0"}}'),
            "'ffmpeg'*" => Process::result('', 'Decoding requested, but no decoder found for: alac', 1),
        ]);

        try {
            app(AudioConverter::class)->measure($path);
            $this->fail('復号できない音声が測れてしまった');
        } catch (AudioUnreadable $exception) {
            $this->assertStringContainsString('音声を復号できない(ffmpeg の終了コード 1)', $exception->getMessage());
        }

        Process::assertRan(fn ($process): bool => str_starts_with((string) $process->command[0], 'ffmpeg')
            && in_array('-t', $process->command, true));
    }

    /**
     * 入力は受ける demuxer(mp3・mov・wav)と file の protocol だけに絞る。手元の全部入りの ffmpeg なら読める形式
     * (ogg・ほかのファイルを参照する concat の一覧)と、file 以外の protocol(開く前に断るので接続しない)は読み取れない音声になる。
     */
    public function test_formats_and_protocols_outside_the_allowed_lists_cannot_be_measured(): void
    {
        $wav = $this->audio('sin(2*PI*440*t)', 5);
        $ogg = "{$this->directory}/audio.ogg";
        $this->ffmpeg(['-i', $wav, '-c:a', 'flac', '-f', 'ogg', $ogg]);
        $concat = "{$this->directory}/list.txt";
        File::put($concat, "ffconcat version 1.0\nfile '".basename($wav)."'\n");

        foreach ([$ogg, $concat, 'http://127.0.0.1:9/audio.wav'] as $path) {
            try {
                app(AudioConverter::class)->measure($path);
                $this->fail("{$path} が測れてしまった");
            } catch (AudioUnreadable $exception) {
                $this->assertStringContainsString('not on whitelist', $exception->getMessage(), $path);
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

    /** D = 25・C = 10・W = 2 → n = ceil(25 ÷ 8) = 4、目標 6.25・12.5・18.75。無音 5〜6・11〜12 秒の中ほどに寄り、3 つ目は無音が無く目標で切る */
    public function test_the_boundaries_move_to_the_middle_of_the_last_silence_before_the_target(): void
    {
        $chunks = $this->split($this->audio('if(between(t,5,6)+between(t,11,12),0,sin(2*PI*440*t))', 25), 10);

        $this->assertBounds([[0, 5.5], [5.5, 11.5], [11.5, 18.75], [18.75, 25]], $chunks);
        $this->assertTrailingSilences([0.5, 0.5, 0, 0], $chunks);
    }

    /** D = 25・C = 10・W = 2 → n = 4、無音が無いので目標 6.25・12.5・18.75 で切る */
    public function test_without_silence_the_audio_is_cut_at_the_target_times(): void
    {
        $chunks = $this->split($this->audio('sin(2*PI*440*t)', 25), 10);

        $this->assertBounds([[0, 6.25], [6.25, 12.5], [12.5, 18.75], [18.75, 25]], $chunks);
        $this->assertTrailingSilences([0, 0, 0, 0], $chunks);
    }

    /**
     * 音は 0〜10 秒だけ。D = 25 → n = 4、目標 6.25・12.5 は幅の中に無音の中ほど(17.5 秒)が無く目標で切り、18.75 は 17.5 秒に寄る。
     * 全体が無音の区切りは、終わりの無音が区切りの長さになる。
     */
    public function test_the_chunks_tell_their_trailing_silence(): void
    {
        $chunks = $this->split($this->audio('if(lt(t,10),sin(2*PI*440*t),0)', 25), 10);

        $this->assertBounds([[0, 6.25], [6.25, 12.5], [12.5, 17.5], [17.5, 25]], $chunks);
        $this->assertTrailingSilences([0, 2.5, 5, 7.5], $chunks);
    }

    /** D = 11・C = 10・W = 2 → n = ceil(11 ÷ 8) = 2、目標 5.5。区切りの長さを少し超える音声は、ほぼ等しい 2 つになる(極く短い区切りができない) */
    public function test_an_audio_a_little_longer_than_a_chunk_becomes_two_nearly_equal_chunks(): void
    {
        $chunks = $this->split($this->audio('sin(2*PI*440*t)', 11), 10);

        $this->assertBounds([[0, 5.5], [5.5, 11]], $chunks);
    }

    /** D = 10 ≤ C → 区切りは 1 つ(境が無い) */
    public function test_an_audio_not_longer_than_a_chunk_is_one_chunk(): void
    {
        $this->assertBounds([[0, 10]], $this->split($this->audio('sin(2*PI*440*t)', 10), 10));
    }

    /**
     * @return array<string, array{int, list<array{float, float}>}>
     */
    public static function lengths(): array
    {
        return [
            '17 秒' => [17, [[0, 5.67], [5.67, 10.8], [10.8, 17]]],
            '35 秒' => [35, [[0, 6.3], [6.3, 14], [14, 19.8], [19.8, 28], [28, 35]]],
            '41 秒' => [41, [[0, 6.3], [6.3, 13.67], [13.67, 19.8], [19.8, 27.33], [27.33, 33.3], [33.3, 41]]],
        ];
    }

    /**
     * 区切りの数と境は docs の 設計/AI/実行と費用.md 4.2 の決め方で、どの区切りも 2 秒より長い(短い区切りを作らない)。
     * 無音は 1.4 秒から 4.5 秒ごとに 0.8 秒で、境の一部は無音の中ほどへ寄り、ほかは目標の時刻で切る。
     *
     * @param  list<array{float, float}>  $expected
     */
    #[DataProvider('lengths')]
    public function test_the_chunks_split_evenly_and_move_to_silence(int $seconds, array $expected): void
    {
        $chunks = $this->split($this->audio('if(lt(mod(t+3.1,4.5),0.8),0,sin(2*PI*440*t))', $seconds), 10);

        $this->assertBounds($expected, $chunks);

        foreach ($chunks as $chunk) {
            $this->assertGreaterThan(2.0, $chunk->lengthSeconds);
        }
    }

    /** 道具が見つからない(シェルの終了コード 127)・走らせられない(126)のは音声のせいでないので、読み取れない音声でなく変換の失敗になる */
    public function test_a_tool_that_does_not_run_is_a_conversion_failure_not_an_unreadable_audio(): void
    {
        $path = $this->audio('sin(2*PI*440*t)', 5);

        foreach ([127, 126] as $exitCode) {
            Process::fake(['*' => Process::result('', 'command not found', $exitCode)]);

            foreach ([fn () => app(AudioConverter::class)->measure($path), fn () => $this->split($path, 10)] as $call) {
                try {
                    $call();
                    $this->fail('道具が走らないのに変換の失敗にならなかった');
                } catch (AudioConversionFailed $exception) {
                    $this->assertNotInstanceOf(AudioUnreadable::class, $exception);
                    $this->assertStringContainsString("終了コード {$exitCode}", $exception->getMessage());
                }
            }
        }
    }

    /**
     * 書き手の無い名前つきパイプは開いたまま終わらないので、測りは測りの時間の上限で、区切りは変換の時間の上限で変換の失敗になる
     * (もう一方の上限を長くしても、自分の上限(1 秒)で終わる)。
     */
    public function test_a_process_over_the_time_limit_is_a_conversion_failure(): void
    {
        $pipe = "{$this->directory}/pipe.wav";
        $this->assertTrue(posix_mkfifo($pipe, 0600));
        $measure = fn () => app(AudioConverter::class)->measure($pipe);
        $split = fn () => $this->split($pipe, 10);
        $cases = [
            'measure' => [['measure_timeout_seconds' => 1, 'conversion_timeout_seconds' => 3], $measure],
            'split' => [['measure_timeout_seconds' => 3, 'conversion_timeout_seconds' => 1], $split],
        ];

        foreach ($cases as $name => [$limits, $call]) {
            config(['ai_runs.transcription' => [...(array) config('ai_runs.transcription'), ...$limits]]);

            try {
                $call();
                $this->fail("{$name}: 時間の上限を超えたのに変換の失敗にならなかった");
            } catch (AudioConversionFailed $exception) {
                $this->assertNotInstanceOf(AudioUnreadable::class, $exception, $name);
                $this->assertStringContainsString('時間の上限', $exception->getMessage(), $name);
                $this->assertStringContainsString('timeout of 1 seconds', $exception->getMessage(), $name);
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
