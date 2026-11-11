<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * 種類によらない文字起こしの値(config/ai_runs.php の transcription。見えない値 = docs の 設計/AI/実行と費用/README.md 4.2・5 節)を読む 1 か所と、
 * 文字起こしのジョブの時間(4.1)。形が合わない設定は、値を使う所では作りの誤り(LogicException)、起動の時の確かめ({@see self::problems()})では
 * 食い違いの文(ほかの確かめを続ける)。
 *
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 */
class TranscriptionSettings
{
    /** 無音を探す幅の鍵 */
    public const SILENCE_WINDOW = 'silence_window_seconds';

    /** 変換と区切りの時間の上限の鍵 */
    public const CONVERSION_TIMEOUT = 'conversion_timeout_seconds';

    /** 正の整数の値 */
    private const POSITIVE_INTS = [
        'conversion_timeout_seconds', 'measure_timeout_seconds', 'decode_check_seconds', 'silence_window_seconds',
        'channels', 'sample_rate', 'bitrate_kbps',
    ];

    /** 正の数の値 */
    private const POSITIVE_NUMBERS = ['silence_min_seconds', 'incomplete_tolerance_seconds'];

    private const SECTION = 'config/ai_runs.php の transcription';

    /**
     * 起動の時の確かめ: 文字起こしの値の形の食い違い(どの値も投げずに文にする)。無ければ空。
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $values = config('ai_runs.transcription');

        if (! is_array($values)) {
            return [self::SECTION.' が配列でない'];
        }

        $problems = [];

        foreach (self::POSITIVE_INTS as $key) {
            $problems = [...$problems, ...self::positiveIntProblems($values, $key)];
        }

        foreach (self::POSITIVE_NUMBERS as $key) {
            $problems = [...$problems, ...self::positiveProblems($values, $key)];
        }

        return [...$problems, ...self::noiseProblems($values), ...self::directoryProblems($values)];
    }

    /**
     * 形の判定: 文字起こしの値が配列で、正の整数の値 $keys がどれも正の整数か(起動の時の確かめが止めるかを決める =
     * docs の 設計/AI/実行と費用/接続先と費用.md 5.4)。
     *
     * @param  list<string>  $keys
     */
    public function positiveIntsFit(array $keys): bool
    {
        $values = config('ai_runs.transcription');

        return is_array($values)
            && array_all($keys, fn (string $key): bool => self::positiveIntProblems($values, $key) === []);
    }

    /** 実行の中の変換と区切りの時間の上限(秒。外部のプロセスすべてを合わせた長さ) */
    public function conversionTimeoutSeconds(): int
    {
        return $this->positiveInt(self::CONVERSION_TIMEOUT);
    }

    /** 依頼の時点の長さの測りと先頭の復号の確かめの時間の上限(秒。外部のプロセスを合わせた長さ。変換と分ける理由 = 設計/AI/実行と費用/README.md 4.2) */
    public function measureTimeoutSeconds(): int
    {
        return $this->positiveInt('measure_timeout_seconds');
    }

    /** 依頼の時点に長さを測るとき、復号できるかを確かめる先頭の長さ(秒) */
    public function decodeCheckSeconds(): int
    {
        return $this->positiveInt('decode_check_seconds');
    }

    /** 無音を探す幅(秒。区切りの目標の時刻の前のこの幅の中の無音に境を寄せる) */
    public function silenceWindowSeconds(): int
    {
        return $this->positiveInt(self::SILENCE_WINDOW);
    }

    /** 無音とみなす音の大きさの上限(dB。負の数) */
    public function silenceNoiseDb(): float
    {
        $values = $this->values();
        self::throwFirst(self::noiseProblems($values));

        return (float) $values['silence_noise_db'];
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
        $values = $this->values();
        self::throwFirst(self::directoryProblems($values));

        return rtrim((string) $values['temp_directory'], '/');
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
     * 設計/AI/実行と費用/README.md 4.1)。
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
        $values = $this->values();
        self::throwFirst(self::positiveIntProblems($values, $key));

        return (int) $values[$key];
    }

    private function positive(string $key): float
    {
        $values = $this->values();
        self::throwFirst(self::positiveProblems($values, $key));

        return (float) $values[$key];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function positiveIntProblems(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        return is_int($value) && $value > 0 ? [] : [self::SECTION.".{$key} が正の整数でない"];
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function positiveProblems(array $values, string $key): array
    {
        $number = self::numberProblems($values, $key);

        return match (true) {
            $number !== [] => $number,
            $values[$key] > 0 => [],
            default => [self::SECTION.".{$key} が正の数でない"],
        };
    }

    /**
     * 無音とみなす音の大きさの上限が負の数か。
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function noiseProblems(array $values): array
    {
        $key = 'silence_noise_db';
        $number = self::numberProblems($values, $key);

        return match (true) {
            $number !== [] => $number,
            $values[$key] < 0 => [],
            default => [self::SECTION.".{$key} が負の数でない"],
        };
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function numberProblems(array $values, string $key): array
    {
        $value = $values[$key] ?? null;

        return is_int($value) || is_float($value) ? [] : [self::SECTION.".{$key} が数でない"];
    }

    /**
     * 区切りの一時ファイルの置き場が空でない文字列か。
     *
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private static function directoryProblems(array $values): array
    {
        $directory = $values['temp_directory'] ?? null;

        return is_string($directory) && $directory !== '' ? [] : [self::SECTION.'.temp_directory が空でない文字列でない'];
    }

    /**
     * 値を使う所の形の食い違いを作りの誤りにする(最初の文)。
     *
     * @param  list<string>  $problems
     *
     * @throws LogicException 食い違いがあるとき
     */
    private static function throwFirst(array $problems): void
    {
        if ($problems !== []) {
            throw new LogicException($problems[0]);
        }
    }

    /**
     * @return array<array-key, mixed>
     */
    private function values(): array
    {
        $values = config('ai_runs.transcription');

        return is_array($values) ? $values : throw new LogicException(self::SECTION.' が配列でない');
    }
}
