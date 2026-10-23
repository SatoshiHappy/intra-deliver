<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * AI の実行の種類の設定(config/ai_runs.php の kinds。docs の 設計/AI/実行と費用.md 5 節)を読む 1 か所。
 * 形が合わない設定は作りの誤り(LogicException)。
 * 種類のコードは . を含むので、config() の . の区切りを通さず kinds の配列から引く。必須の鍵は種類の型(文章・文字起こし)ごとに違い、
 * 型は鍵の有無で推さず type に書く(起動の時に {@see self::problems()} で確かめる = 設計/AI/データと失敗と検査.md 8 節)。
 *
 * @phpstan-type PromptKindSettings array{
 *     type: 'prompt', provider: string, model: string, budget: string, timeout_seconds: int,
 *     max_output_tokens: int, max_input_chars: int, provider_options: array<string, mixed>
 * }
 * @phpstan-type TranscriptionKindSettings array{
 *     type: 'transcription', provider: string, model: string, budget: string, timeout_seconds: int,
 *     language: string, max_audio_seconds: int, chunk_seconds: int
 * }
 */
class KindSettings
{
    /** 種類の型: 文章(LLM の呼び出し 1 回) */
    public const PROMPT = 'prompt';

    /** 種類の型: 文字起こし(音声 1 本の区切りごとの呼び出し) */
    public const TRANSCRIPTION = 'transcription';

    /** 接続先の要求に足す指定のうち、種類の設定が持たない鍵(実行ごとに訪問者の ID を入れる = docs の 設計/AI/実行と費用.md 4.4) */
    public const RUN_OPTION_KEY = 'metadata';

    /** 必須の鍵(共通と型ごと。値は空でない文字列 string か正の整数 int) */
    private const REQUIRED_KEYS = [
        '' => ['provider' => 'string', 'model' => 'string', 'budget' => 'string', 'timeout_seconds' => 'int'],
        self::PROMPT => ['max_output_tokens' => 'int', 'max_input_chars' => 'int'],
        self::TRANSCRIPTION => ['language' => 'string', 'max_audio_seconds' => 'int', 'chunk_seconds' => 'int'],
    ];

    /**
     * 設定にある種類のコード。型を渡すと、その型の種類だけ。
     *
     * @return list<string>
     */
    public function codes(?string $type = null): array
    {
        $codes = [];

        foreach ($this->kinds() as $code => $kind) {
            if ($type === null || (is_array($kind) && ($kind['type'] ?? null) === $type)) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /**
     * 種類の設定の形の食い違い(型と、型ごとの必須の鍵と、接続先の要求に足す指定が {@see self::RUN_OPTION_KEY} を持たないこと。
     * 持つと、実行ごとに混ぜる値と混ぜる順で片方が消える)。設定に無いコードは空。
     *
     * @return list<string>
     */
    public function problems(string $code): array
    {
        $kind = $this->kinds()[$code] ?? null;
        $type = is_array($kind) ? ($kind['type'] ?? null) : null;

        if ($kind === null) {
            return [];
        }

        if (! is_array($kind) || ! is_string($type) || $type === '' || ! isset(self::REQUIRED_KEYS[$type])) {
            return ["種類 {$code} の type が prompt・transcription のどちらでもない(config/ai_runs.php の kinds)"];
        }

        $wrong = self::wrongKeys($kind, $type);
        $keys = implode('・', $wrong);
        $problems = $wrong === [] ? [] : ["種類 {$code}({$type})の {$keys} が無いか形が合わない(config/ai_runs.php の kinds)"];
        $options = $kind['provider_options'] ?? [];

        $runKey = self::RUN_OPTION_KEY;
        $carriesRunKey = "種類 {$code}({$type})の provider_options が実行ごとに足す鍵 {$runKey} を持つ(config/ai_runs.php の kinds)";

        return is_array($options) && array_key_exists($runKey, $options) ? [...$problems, $carriesRunKey] : $problems;
    }

    /**
     * 文章の種類の設定。設定に無いコード・文字起こしの種類なら null。
     *
     * @return PromptKindSettings|null
     *
     * @throws LogicException 設定の形が合わないとき
     */
    public function prompt(string $code): ?array
    {
        $kind = $this->ofType($code, self::PROMPT);

        return $kind === null ? null : [
            'type' => self::PROMPT,
            'provider' => (string) $kind['provider'],
            'model' => (string) $kind['model'],
            'budget' => (string) $kind['budget'],
            'timeout_seconds' => (int) $kind['timeout_seconds'],
            'max_output_tokens' => (int) $kind['max_output_tokens'],
            'max_input_chars' => (int) $kind['max_input_chars'],
            'provider_options' => self::stringKeyed((array) ($kind['provider_options'] ?? []), $code),
        ];
    }

    /**
     * 文字起こしの種類の設定。設定に無いコード・文章の種類なら null。
     *
     * @return TranscriptionKindSettings|null
     *
     * @throws LogicException 設定の形が合わないとき
     */
    public function transcription(string $code): ?array
    {
        $kind = $this->ofType($code, self::TRANSCRIPTION);

        return $kind === null ? null : [
            'type' => self::TRANSCRIPTION,
            'provider' => (string) $kind['provider'],
            'model' => (string) $kind['model'],
            'budget' => (string) $kind['budget'],
            'timeout_seconds' => (int) $kind['timeout_seconds'],
            'language' => (string) $kind['language'],
            'max_audio_seconds' => (int) $kind['max_audio_seconds'],
            'chunk_seconds' => (int) $kind['chunk_seconds'],
        ];
    }

    /**
     * 型の必須の鍵のうち、無いか形が合わない鍵(接続先の要求に足す指定は任意で、あれば配列)。
     *
     * @param  array<array-key, mixed>  $kind
     * @return list<string>
     */
    private static function wrongKeys(array $kind, string $type): array
    {
        $wrong = [];

        foreach ([...self::REQUIRED_KEYS[''], ...self::REQUIRED_KEYS[$type]] as $key => $form) {
            $value = $kind[$key] ?? null;
            $valid = $form === 'int' ? is_int($value) && $value > 0 : is_string($value) && $value !== '';
            $wrong = $valid ? $wrong : [...$wrong, $key];
        }

        return is_array($kind['provider_options'] ?? []) ? $wrong : [...$wrong, 'provider_options'];
    }

    /**
     * 型を確かめた種類の設定の配列。設定に無いコード・型が違うなら null。
     *
     * @return array<array-key, mixed>|null
     *
     * @throws LogicException 設定の形が合わないとき
     */
    private function ofType(string $code, string $type): ?array
    {
        $kind = $this->kinds()[$code] ?? null;
        $problems = $this->problems($code);

        if ($problems !== []) {
            throw new LogicException(implode(' / ', $problems));
        }

        return is_array($kind) && $kind['type'] === $type ? $kind : null;
    }

    /**
     * 接続先の要求に足す指定(鍵は文字列)。
     *
     * @param  array<array-key, mixed>  $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values, string $code): array
    {
        $keyed = [];
        $error = "config/ai_runs.php の kinds.{$code}.provider_options の鍵が文字列でない";

        foreach ($values as $key => $value) {
            $keyed[is_string($key) ? $key : throw new LogicException($error)] = $value;
        }

        return $keyed;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function kinds(): array
    {
        $kinds = config('ai_runs.kinds');

        return is_array($kinds) ? $kinds : throw new LogicException('config/ai_runs.php の kinds が配列でない');
    }
}
