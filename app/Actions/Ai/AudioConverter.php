<?php

namespace App\Actions\Ai;

use App\Queries\Ai\TranscriptionSettings;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;

/**
 * 音声の長さを測り、変換して区切る(内部。外部のプロセス ffprobe・ffmpeg を呼ぶ Ai の中の 1 か所 = docs の 設計/AI/実行と費用.md 4.2)。
 * 区切りの境は目標の時刻の前の幅の中の最後の無音の中ほどに寄せ(発言の途中で切らない)、無ければ目標の時刻で切る。寄せるのは前へだけなので、
 * どの区切りも区切りの長さを超えない。外部のプロセスを合わせた時間は変換の時間の上限まで(ジョブの時間に含めた値 = 4.1)。
 */
class AudioConverter
{
    /** 無音の区間と区切りの端を比べるときの許す差(秒。無音の検出の時刻の丸め) */
    private const EDGE_SECONDS = 0.05;

    public function __construct(private TranscriptionSettings $settings) {}

    /**
     * 音声の長さ(秒)。
     *
     * @throws AudioUnreadable 壊れた音声・音声の流れが無いとき
     * @throws AudioConversionFailed 外部のプロセスの時間切れ・起動の失敗のとき
     */
    public function measure(string $path): float
    {
        return $this->duration($path, $this->deadline());
    }

    /**
     * 音声を変換の形(設定のチャンネル・標本化の周波数・ビットレートの mp3)にして区切り、$directory に区切りのファイルを書く。
     *
     * @return list<AudioChunk> 区切り(時刻の順。始まりの秒は前の区切りの終わりと同じ)
     *
     * @throws AudioConversionFailed 測れない・変換できない・時間の上限を超えたとき
     */
    public function split(string $path, string $directory, int $chunkSeconds): array
    {
        $deadline = $this->deadline();
        $duration = $this->duration($path, $deadline);
        $silences = $this->silences($path, $duration, $deadline);
        File::ensureDirectoryExists($directory);
        $chunks = [];

        foreach ($this->bounds($duration, $silences, $chunkSeconds) as $index => [$start, $end]) {
            $chunkPath = sprintf('%s/chunk-%03d.mp3', rtrim($directory, '/'), $index + 1);
            $this->encode($path, $chunkPath, $start, $end - $start, $deadline);
            $chunks[] = new AudioChunk(
                $chunkPath,
                $start,
                $end - $start,
                self::trailingSilence($silences, $start, $end),
                self::isSilent($silences, $start, $end),
            );
        }

        return $chunks;
    }

    /**
     * @throws AudioUnreadable 壊れた音声・音声の流れが無いとき
     */
    private function duration(string $path, float $deadline): float
    {
        $result = $this->run(
            ['ffprobe', '-v', 'error', '-show_entries', 'stream=codec_type:format=duration', '-of', 'json', $path],
            $deadline,
        );
        $probe = json_decode($result->output(), true);
        $streams = is_array($probe) && is_array($probe['streams'] ?? null) ? $probe['streams'] : [];
        $hasAudio = in_array('audio', array_column($streams, 'codec_type'), true);
        $duration = is_array($probe) ? ($probe['format']['duration'] ?? null) : null;

        if ($result->failed() || ! $hasAudio || ! is_numeric($duration) || (float) $duration <= 0) {
            throw new AudioUnreadable("音声の長さを測れない(ffprobe の終了コード {$result->exitCode()}): ".self::excerpt($result));
        }

        return (float) $duration;
    }

    /**
     * 無音の区間(始まりと終わりの秒。時刻の順)。終わりまで続く無音は音声の長さで閉じる。
     *
     * @return list<array{float, float}>
     */
    private function silences(string $path, float $duration, float $deadline): array
    {
        $noise = $this->settings->silenceNoiseDb();
        $filter = sprintf('silencedetect=noise=%sdB:d=%s', $noise, $this->settings->silenceMinSeconds());
        $result = $this->run(
            ['ffmpeg', '-hide_banner', '-nostdin', '-nostats', '-i', $path, '-vn', '-af', $filter, '-f', 'null', '-'],
            $deadline,
        );
        self::ensureSucceeded($result, '無音を探せない');
        preg_match_all('/silence_(start|end): (-?[0-9.]+)/', $result->errorOutput(), $matches, PREG_SET_ORDER);
        $silences = [];
        $start = null;

        foreach ($matches as [, $edge, $seconds]) {
            if ($edge === 'start') {
                $start = max(0.0, (float) $seconds);
            } elseif ($start !== null) {
                $silences[] = [$start, (float) $seconds];
                $start = null;
            }
        }

        return $start === null ? $silences : [...$silences, [$start, $duration]];
    }

    /**
     * 区切りの境(始まりと終わりの秒)。
     *
     * @param  list<array{float, float}>  $silences
     * @return list<array{float, float}>
     */
    private function bounds(float $duration, array $silences, int $chunkSeconds): array
    {
        $window = $this->settings->silenceWindowSeconds();
        $bounds = [];
        $start = 0.0;

        while ($duration - $start > $chunkSeconds) {
            $target = $start + $chunkSeconds;
            $end = self::lastSilenceMiddle($silences, max($start, $target - $window), $target) ?? $target;
            $bounds[] = [$start, $end];
            $start = $end;
        }

        return [...$bounds, [$start, $duration]];
    }

    private function encode(string $path, string $chunkPath, float $start, float $length, float $deadline): void
    {
        $result = $this->run([
            'ffmpeg', '-hide_banner', '-nostdin', '-v', 'error', '-y',
            '-ss', sprintf('%.3f', $start), '-t', sprintf('%.3f', $length), '-i', $path, '-vn',
            '-ac', (string) $this->settings->channels(), '-ar', (string) $this->settings->sampleRate(),
            '-c:a', 'libmp3lame', '-b:a', $this->settings->bitrateKbps().'k', $chunkPath,
        ], $deadline);
        self::ensureSucceeded($result, '音声を変換できない');
    }

    /**
     * 外部のプロセスを残りの時間の上限で走らせる。
     *
     * @param  list<string>  $command
     *
     * @throws AudioConversionFailed 時間の上限を超えた・起動できないとき
     */
    private function run(array $command, float $deadline): ProcessResult
    {
        $remaining = (int) ceil($deadline - microtime(true));

        if ($remaining <= 0) {
            throw new AudioConversionFailed("音声の変換が時間の上限 {$this->settings->conversionTimeoutSeconds()} 秒を超えた");
        }

        try {
            return Process::timeout($remaining)->run($command);
        } catch (ProcessException $exception) {
            $message = "{$command[0]} が時間の上限までに終わらないか起動できない: {$exception->getMessage()}";

            throw new AudioConversionFailed($message, 0, $exception);
        }
    }

    private function deadline(): float
    {
        return microtime(true) + $this->settings->conversionTimeoutSeconds();
    }

    /**
     * 中ほどが [$from, $to] にある最後の無音の中ほど($from より後ろ)。無ければ null。
     *
     * @param  list<array{float, float}>  $silences
     */
    private static function lastSilenceMiddle(array $silences, float $from, float $to): ?float
    {
        $found = null;

        foreach ($silences as [$silenceStart, $silenceEnd]) {
            $middle = ($silenceStart + $silenceEnd) / 2;
            $found = $middle > $from && $middle <= $to ? $middle : $found;
        }

        return $found;
    }

    /**
     * 区切りの終わりにかかる無音の、区切りの中の長さ。
     *
     * @param  list<array{float, float}>  $silences
     */
    private static function trailingSilence(array $silences, float $start, float $end): float
    {
        foreach ($silences as [$silenceStart, $silenceEnd]) {
            if ($silenceStart < $end && $silenceEnd >= $end - self::EDGE_SECONDS) {
                return $end - max($silenceStart, $start);
            }
        }

        return 0.0;
    }

    /**
     * 区切りの全体が 1 つの無音の中にあるか。
     *
     * @param  list<array{float, float}>  $silences
     */
    private static function isSilent(array $silences, float $start, float $end): bool
    {
        foreach ($silences as [$silenceStart, $silenceEnd]) {
            if ($silenceStart <= $start + self::EDGE_SECONDS && $silenceEnd >= $end - self::EDGE_SECONDS) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws AudioConversionFailed 終了コードが 0 でないとき
     */
    private static function ensureSucceeded(ProcessResult $result, string $what): void
    {
        if ($result->failed()) {
            throw new AudioConversionFailed("{$what}(ffmpeg の終了コード {$result->exitCode()}): ".self::excerpt($result));
        }
    }

    /** 誤りの出力の頭(例外の文に載せる。記録に残す文の長さは失敗を書く側が切る) */
    private static function excerpt(ProcessResult $result): string
    {
        return mb_substr(trim($result->errorOutput()), 0, 500);
    }
}
