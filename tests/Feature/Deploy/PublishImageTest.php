<?php

namespace Tests\Feature\Deploy;

use App\Actions\Ai\AudioConverter;
use Tests\TestCase;

/**
 * 公開用の app のイメージ(Dockerfile)と起動のスクリプト(docker/app/entrypoint)の守り(docs の 設計/公開の配備/構成.md の 3.3.1 節)を、
 * 文で確かめる。イメージの中で見るもの(拡張の有無・ffmpeg の構成の行・起動)は build と実機の検証で見る。
 */
final class PublishImageTest extends TestCase
{
    public function test_the_base_stage_installs_pcntl_for_the_queue_worker(): void
    {
        $this->assertMatchesRegularExpression('/^RUN install-php-extensions [^\n]*\bpcntl\b/m', $this->dockerfile());
    }

    public function test_ffmpeg_is_built_on_the_pinned_base_from_a_fixed_version_checked_by_sha256(): void
    {
        $dockerfile = $this->dockerfile();

        $this->assertStringContainsString('FROM ${FRANKENPHP_IMAGE} AS ffmpeg', $dockerfile);
        $this->assertMatchesRegularExpression('/^ARG FFMPEG_VERSION=\d+\.\d+(\.\d+)?$/m', $dockerfile);
        $this->assertMatchesRegularExpression('/^ARG FFMPEG_SHA256=[0-9a-f]{64}$/m', $dockerfile);
        $this->assertStringContainsString('echo "${FFMPEG_SHA256}  ffmpeg.tar.xz" | sha256sum -c -', $dockerfile);
        $this->assertStringContainsString(
            'COPY --from=ffmpeg /opt/ffmpeg/bin/ffmpeg /opt/ffmpeg/bin/ffprobe /usr/local/bin/',
            $dockerfile,
        );
    }

    public function test_ffmpeg_is_configured_without_gpl_or_nonfree_hardened_and_checked_in_the_build(): void
    {
        $dockerfile = $this->dockerfile();

        $this->assertStringContainsString('--disable-everything --disable-autodetect', $dockerfile);
        $this->assertStringContainsString('--toolchain=hardened', $dockerfile);
        $this->assertStringNotContainsString('--enable-gpl', $dockerfile);
        $this->assertStringNotContainsString('--enable-nonfree', $dockerfile);
        $this->assertStringContainsString('RUN PATH="/opt/ffmpeg/bin:$PATH" sh /usr/src/ffmpeg-check', $dockerfile);
    }

    /** イメージの ffmpeg の demuxer は、アプリが入力を絞る一覧(AudioConverter::DEMUXERS)と同じ */
    public function test_the_demuxers_of_the_image_are_the_ones_the_app_allows(): void
    {
        preg_match_all('/--enable-demuxer=(\S+)/', $this->stage('ffmpeg'), $matches);
        $demuxers = explode(',', implode(',', $matches[1]));
        sort($demuxers);
        $allowed = AudioConverter::DEMUXERS;
        sort($allowed);

        $this->assertSame($allowed, $demuxers);
    }

    /** 受ける符号化の decoder 1 つに見本 1 つ(docker/app/ffmpeg-check/samples/<decoder>.<拡張子>)。片方だけ足すと落ちる */
    public function test_each_decoder_has_exactly_one_sample(): void
    {
        preg_match_all('/--enable-decoder=(\S+)/', $this->stage('ffmpeg'), $matches);
        $decoders = explode(',', implode(',', $matches[1]));
        $samples = array_map(
            fn (string $file): string => pathinfo($file, PATHINFO_FILENAME),
            glob(base_path('docker/app/ffmpeg-check/samples/*')) ?: [],
        );
        sort($decoders);
        sort($samples);

        $this->assertNotSame([], $decoders);
        $this->assertSame($decoders, $samples);
    }

    /** アプリの呼び方の確かめは、アプリのコードと base の ffmpeg がそろう build の段で、実物の AudioConverter を見本に流す */
    public function test_the_build_stage_runs_the_audio_check_on_the_samples(): void
    {
        $build = $this->stage('build');

        $this->assertStringContainsString('RUN php artisan ai:check-audio docker/app/ffmpeg-check/samples/*', $build);
        $this->assertGreaterThan(
            strpos($build, 'composer dump-autoload'),
            strpos($build, 'php artisan ai:check-audio'),
        );
    }

    /** 土台のイメージの HEALTHCHECK を、同じイメージの queue・scheduler・一回きりのコンテナに受け継がない(app は compose で決める) */
    public function test_the_app_stage_drops_the_healthcheck_of_the_base_image(): void
    {
        $this->assertMatchesRegularExpression('/^HEALTHCHECK NONE$/m', $this->stage('app'));
    }

    /**
     * 起動の時の確かめ(docs の 設計/AI/データと失敗と検査.md の 8 節)は、キャッシュを作った後・引数のコマンドの前に流し、出力は標準エラーへ出す
     * (標準出力は引数のコマンドの出力だけにする)。set -e なので食い違いがあれば起動が止まる。
     */
    public function test_the_entrypoint_checks_the_ai_settings_after_optimize_before_the_command(): void
    {
        $lines = array_map('trim', file(base_path('docker/app/entrypoint'), FILE_IGNORE_NEW_LINES) ?: []);
        $optimize = array_search('php artisan optimize >&2', $lines, true);
        $check = array_search('php artisan ai:check-settings >&2', $lines, true);
        $exec = array_search('exec "$@"', $lines, true);

        $this->assertContains('set -eu', $lines);
        $this->assertIsInt($optimize);
        $this->assertIsInt($check);
        $this->assertIsInt($exec);
        $this->assertGreaterThan($optimize, $check);
        $this->assertLessThan($exec, $check);
    }

    private function dockerfile(): string
    {
        return (string) file_get_contents(base_path('Dockerfile'));
    }

    /** 段 $name の文(その段の FROM から次の FROM の前まで) */
    private function stage(string $name): string
    {
        $pattern = '/^FROM \S+ AS '.$name.'$(.*?)(?=^FROM |\z)/ms';
        $this->assertSame(1, preg_match($pattern, $this->dockerfile(), $matches), $name);

        return $matches[1];
    }
}
