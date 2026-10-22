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
     * 区切りの数の上限(1 本の長さの上限の音声の区切りの数)。
     *
     * @param  TranscriptionKindSettings  $kind
     *
     * @throws LogicException 区切りの長さが無音を探す幅以下のとき
     */
    public function maxChunks(array $kind): int
    {
        return $this->chunkCount($kind['max_audio_seconds'], $kind['chunk_seconds']);
    }

    /**
     * 音声の区切りの数(区切りの長さを超えない最小の数。どの境にも無音を探す幅を残し、無音へ寄せても区切りの長さを超えないため)。
     *
     * @throws LogicException 区切りの長さが無音を探す幅以下のとき
     */
    public function chunkCount(float $audioSeconds, int $chunkSeconds): int
    {
        $shortest = $chunkSeconds - $this->silenceWindowSeconds();

        if ($shortest <= 0) {
            throw new LogicException('区切りの長さが無音を探す幅(config/ai_runs.php の transcription.silence_window_seconds)以下');
        }

        return $audioSeconds <= $chunkSeconds ? 1 : (int) ceil($audioSeconds / $shortest);
    }

    /**
     * 文字起こしのジョブの時間(秒)。区切りごとの時間は文章のジョブと同じ数え方を使う(送り直しの数え方を 1 か所に保つため。
     * 設計/AI/実行と費用.md 4.1)。
     *
     * @param  TranscriptionKindSettings  $kind
     */
    public function jobTimeout(array $kind): int
    {
        $perChunk = AiSettings::jobTimeout($kind['timeout_seconds']);

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
