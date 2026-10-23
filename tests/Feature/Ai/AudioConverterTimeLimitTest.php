<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\AudioChunk;
use App\Actions\Ai\AudioConversionFailed;
use App\Actions\Ai\AudioConverter;
use App\Actions\Ai\AudioUnreadable;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 音声の外部のプロセスの時間の上限(docs の 設計/AI/実行と費用.md 4.2): 測りと区切りは別の上限で、どちらも超えたら読めない音声でなく変換の失敗になる。
 */
final class AudioConverterTimeLimitTest extends TransactionalTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/audio-converter-time-limit-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->directory);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
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
     * @return array<string, array{array<string, int>, string}>
     */
    public static function limitsAlreadyUsedUp(): array
    {
        return [
            '測り' => [['measure_timeout_seconds' => 1, 'conversion_timeout_seconds' => 60], 'measure'],
            '区切り' => [['measure_timeout_seconds' => 60, 'conversion_timeout_seconds' => 1], 'split'],
        ];
    }

    /**
     * 最初の外部のプロセス(長さの測り)で上限の時間を使い切ったら、次の外部のプロセスは走らせずに変換の失敗にする
     * (測りは測りの、区切りは区切りの上限が効く)。
     *
     * @param  array<string, int>  $limits
     */
    #[DataProvider('limitsAlreadyUsedUp')]
    public function test_the_next_process_is_not_run_after_the_limit_has_passed(array $limits, string $operation): void
    {
        config(['ai_runs.transcription' => [...(array) config('ai_runs.transcription'), ...$limits]]);
        $probe = '{"streams":[{"codec_type":"audio"}],"format":{"duration":"5.0"}}';
        Process::fake([
            '*ffprobe*' => function () use ($probe) {
                usleep(1_200_000);

                return Process::result($probe);
            },
            '*ffmpeg*' => Process::result(''),
        ]);

        try {
            $operation === 'measure'
                ? app(AudioConverter::class)->measure('/dev/null')
                : $this->split('/dev/null', 10);
            $this->fail('上限を使い切った後の外部のプロセスが走った');
        } catch (AudioConversionFailed $exception) {
            $this->assertStringContainsString('ffmpeg の前に', $exception->getMessage());
        }

        Process::assertRanTimes(fn (PendingProcess $process) => self::toolOf($process) === 'ffprobe', 1);
        Process::assertRanTimes(fn (PendingProcess $process) => self::toolOf($process) === 'ffmpeg', 0);
    }

    private static function toolOf(PendingProcess $process): mixed
    {
        return is_array($process->command) ? $process->command[0] : $process->command;
    }

    /**
     * @return list<AudioChunk>
     */
    private function split(string $path, int $chunkSeconds): array
    {
        return app(AudioConverter::class)->split($path, "{$this->directory}/chunks", $chunkSeconds);
    }
}
