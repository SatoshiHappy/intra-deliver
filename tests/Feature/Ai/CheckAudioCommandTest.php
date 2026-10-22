<?php

namespace Tests\Feature\Ai;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * 音声の確かめのコマンド ai:check-audio(公開用のイメージの build が見本の音声に流す = docs の 設計/公開の配備/構成.md の 3.3.1 節)を、
 * 手元の ffmpeg・ffprobe で見本の音声(受ける decoder ごとに 1 つ)に流す。見本は通り、音声でないファイルは終了コード 1 と理由。DB を使わない基底クラス。
 */
final class CheckAudioCommandTest extends TestCase
{
    /** decoder の名前が符号化の名前(ffprobe の codec_name)と違うもの */
    private const DECODER_CODECS = ['mp3float' => 'mp3'];

    public function test_the_samples_of_the_image_build_pass(): void
    {
        // build と同じく置き場の見本をすべて流す(見本を足せばここも流れる)
        $files = glob(base_path('docker/app/ffmpeg-check/samples/*')) ?: [];
        $this->assertNotSame([], $files);

        $exitCode = Artisan::call('ai:check-audio', ['files' => $files]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode, $output);

        foreach ($files as $file) {
            $line = '/^'.preg_quote($file, '/').': 長さ \d+\.\d{3} 秒・区切り [1-9]\d* 個/m';
            $this->assertMatchesRegularExpression($line, $output);
        }
    }

    /**
     * 見本はそれぞれ名前の decoder の符号化で作ってある(decoder の名前と符号化の名前が違うものは表で引く)。
     * 見本が別の符号化だと、その decoder を build で確かめたことにならないため。
     */
    public function test_each_sample_is_encoded_with_the_codec_of_its_decoder(): void
    {
        foreach (glob(base_path('docker/app/ffmpeg-check/samples/*')) ?: [] as $file) {
            $decoder = pathinfo($file, PATHINFO_FILENAME);
            $probe = ['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_name', '-of', 'csv=p=0', $file];
            $result = Process::run($probe);

            $this->assertTrue($result->successful(), $result->errorOutput());
            $this->assertSame(self::DECODER_CODECS[$decoder] ?? $decoder, trim($result->output()), $file);
        }
    }

    public function test_a_file_that_is_not_audio_fails_with_the_reason(): void
    {
        $file = base_path('docker/app/ffmpeg-check/check');

        $this->assertSame(1, Artisan::call('ai:check-audio', ['files' => [$file]]));
        $this->assertStringContainsString("{$file}: 音声の長さを測れない", Artisan::output());
    }

    public function test_without_a_transcription_kind_in_the_settings_it_fails(): void
    {
        config(['ai_runs.kinds' => array_filter(
            config('ai_runs.kinds'),
            fn (array $kind): bool => $kind['type'] !== 'transcription',
        )]);

        $sample = base_path('docker/app/ffmpeg-check/samples/pcm_s16le.wav');

        $this->assertSame(1, Artisan::call('ai:check-audio', ['files' => [$sample]]));
        $this->assertStringContainsString('文字起こしの種類が設定(config/ai_runs.php の kinds)に無い', Artisan::output());
    }
}
