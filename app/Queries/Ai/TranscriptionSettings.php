<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * 種類によらない文字起こしの値(config/ai_runs.php の transcription。見えない値 = docs の 設計/AI/実行と費用.md 4.2・5 節)を読む 1 か所と、
 * 文字起こしのジョブの時間(4.1)。形が合わない設定は作りの誤り(LogicException)。
 *
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 */
class TranscriptionSettings
{
    /** 区切りごとの送り直しの待ちの上限(ライブラリは 10 秒以内の待ちの指示にだけ従う) */
    private const RETRY_WAIT_SECONDS = 10;

    /** 変換と長さの測りの時間の上限(秒。外部のプロセスすべてを合わせた長さ) */
    public function conversionTimeoutSeconds(): int
    {
        return $this->positiveInt('conversion_timeout_seconds');
    }

    /** 無音を探す幅(秒。区切りの目標の時刻の前のこの幅の中の無音に境を寄せる) */
    public function silenceWindowSeconds(): int
    {
        return $this->positiveInt('silence_window_seconds');
    }

    /** 無音とみなす音の大きさの上限(dB。負の数) */
    public function silenceNoiseDb(): float
    {
        $db = $this->number('silence_noise_db');

        return $db < 0 ? $db : throw new LogicException('config/ai_runs.php の transcription.silence_noise_db が負の数でない');
    }

    /** 無音とみなす最短の長さ(秒) */
    public function silenceMinSeconds(): float
    {
        return $this->positive('silence_min_seconds');
    }

    /** 不完全の判定で許す差(秒) */
    public function incompleteToleranceSeconds(): float
    {
        return $this->positive('incomplete_tolerance_seconds');
    }

    /** 変換の形のチャンネルの数 */
    public function channels(): int
    {
        return $this->positiveInt('channels');
    }

    /** 変換の形の標本化の周波数(Hz) */
    public function sampleRate(): int
    {
        return $this->positiveInt('sample_rate');
    }

    /** 変換の形のビットレート(kbps) */
    public function bitrateKbps(): int
    {
        return $this->positiveInt('bitrate_kbps');
    }

    /** 区切りの一時ファイルの置き場(この下に実行の id で分けたディレクトリを作る) */
    public function tempDirectory(): string
    {
        $directory = $this->values()['temp_directory'] ?? null;

        return is_string($directory) && $directory !== ''
            ? rtrim($directory, '/')
            : throw new LogicException('config/ai_runs.php の transcription.temp_directory が空でない文字列でない');
    }

    /**
     * 区切りの数の上限 = ceil(1 本の長さの上限 ÷ (区切りの長さ − 無音を探す幅))(境は前へだけ寄せるので、区切りはこの差より短くならない)。
     *
     * @param  TranscriptionKindSettings  $kind
     *
     * @throws LogicException 区切りの長さが無音を探す幅以下のとき
     */
    public function maxChunks(array $kind): int
    {
        $shortest = $kind['chunk_seconds'] - $this->silenceWindowSeconds();

        if ($shortest <= 0) {
            throw new LogicException('区切りの長さが無音を探す幅(config/ai_runs.php の transcription.silence_window_seconds)以下');
        }

        return (int) ceil($kind['max_audio_seconds'] / $shortest);
    }

    /**
     * ジョブの時間(秒) = 変換の時間の上限 + 区切りの数の上限 ×(呼び出しの時間の上限 + 待ち 10 秒)× 2(区切りごとの送り直しの 1 回を含む。
     * 設計/AI/実行と費用.md 4.1)。
     *
     * @param  TranscriptionKindSettings  $kind
     */
    public function jobTimeout(array $kind): int
    {
        $perChunk = ($kind['timeout_seconds'] + self::RETRY_WAIT_SECONDS) * 2;

        return $this->conversionTimeoutSeconds() + $this->maxChunks($kind) * $perChunk;
    }

    private function positiveInt(string $key): int
    {
        $value = $this->values()[$key] ?? null;

        return is_int($value) && $value > 0
            ? $value
            : throw new LogicException("config/ai_runs.php の transcription.{$key} が正の整数でない");
    }

    private function positive(string $key): float
    {
        $value = $this->number($key);

        return $value > 0 ? $value : throw new LogicException("config/ai_runs.php の transcription.{$key} が正の数でない");
    }

    private function number(string $key): float
    {
        $value = $this->values()[$key] ?? null;

        return is_int($value) || is_float($value)
            ? (float) $value
            : throw new LogicException("config/ai_runs.php の transcription.{$key} が数でない");
    }

    /**
     * @return array<array-key, mixed>
     */
    private function values(): array
    {
        $values = config('ai_runs.transcription');

        return is_array($values) ? $values : throw new LogicException('config/ai_runs.php の transcription が配列でない');
    }
}
