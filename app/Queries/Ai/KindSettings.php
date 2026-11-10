<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * AI の実行の種類の設定(config/ai_runs.php の kinds。docs の 設計/AI/実行と費用/接続先と費用.md 5 節)を読む 1 か所。
 * 形が合わない設定は作りの誤り(LogicException)。
 * 種類のコードは . を含むので、config() の . の区切りを通さず kinds の配列から引く。必須の鍵は種類の型(文章・文字起こし・会話)ごとに違い、
 * 型は鍵の有無で推さず type に書く(起動の時に {@see self::problems()} で確かめる = 設計/AI/データと失敗と検査.md 8 節)。
 * 会話の型の種類は接続先とモデルと接続先の要求に足す指定を持たず(用途ごと = {@see ConversationSettings})、用途ごとの呼び出しの時間の上限と
 * 出力の上限を持つ(設計/AI/エージェントの実行/口と契約.md 9.13 の「種類の設定」)。
 *
 * @phpstan-type PromptKindSettings array{
 *     type: 'prompt', provider: string, model: string, budget: string, timeout_seconds: int,
 *     max_output_tokens: int, max_input_chars: int, provider_options: array<string, mixed>
 * }
 * @phpstan-type TranscriptionKindSettings array{
 *     type: 'transcription', provider: string, model: string, budget: string, timeout_seconds: int,
 *     language: string, max_audio_seconds: int, chunk_seconds: int
 * }
 * @phpstan-type ConversationPurposeLimits array{timeout_seconds: int, max_output_tokens: int}
 * @phpstan-type ConversationKindSettings array{
 *     type: 'conversation', budget: string, max_input_chars: int, max_turns: int, run_timeout_seconds: int,
 *     purposes: array<string, ConversationPurposeLimits>
 * }
 * @phpstan-type AnyKindSettings PromptKindSettings|TranscriptionKindSettings|ConversationKindSettings
 */
class KindSettings
{
    /** 種類の型: 文章(LLM の呼び出し 1 回) */
    public const PROMPT = 'prompt';

    /** 種類の型: 文字起こし(音声 1 本の区切りごとの呼び出し) */
    public const TRANSCRIPTION = 'transcription';

    /** 種類の型: 会話(利用者の 1 操作。接続先への送信 1 回 = ステップの列) */
    public const CONVERSATION = 'conversation';

    /** 接続先の要求に足す指定のうち、種類の設定が持たない鍵(実行ごとに訪問者の ID を入れる = docs の 設計/AI/実行と費用/README.md 4.4) */
    public const RUN_OPTION_KEY = 'metadata';

    /** 型ごとの必須の鍵(値は空でない文字列 string か正の整数 int。予算の区分はどの型にもある) */
    private const REQUIRED_KEYS = [
        self::PROMPT => [
            'provider' => 'string', 'model' => 'string', 'budget' => 'string', 'timeout_seconds' => 'int',
            'max_output_tokens' => 'int', 'max_input_chars' => 'int',
        ],
        self::TRANSCRIPTION => [
            'provider' => 'string', 'model' => 'string', 'budget' => 'string', 'timeout_seconds' => 'int',
            'language' => 'string', 'max_audio_seconds' => 'int', 'chunk_seconds' => 'int',
        ],
        self::CONVERSATION => [
            'budget' => 'string', 'max_input_chars' => 'int', 'max_turns' => 'int', 'run_timeout_seconds' => 'int',
        ],
    ];

    /** 会話の型の種類が用途({@see ConversationSettings::PURPOSES})ごとに持つ必須の鍵(正の整数) */
    private const PURPOSE_KEYS = ['timeout_seconds', 'max_output_tokens'];

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
     * 種類の設定の形の食い違い(型と、型ごとの必須の鍵と、接続先の要求に足す指定。文章・文字起こしの足す指定は {@see self::RUN_OPTION_KEY} を
     * 持たない(持つと、実行ごとに混ぜる値と混ぜる順で片方が消える)。会話の型の種類は足す指定を持たない(用途ごとに持つ = 設計/AI/実行と費用/README.md 4.4))。
     * 設定に無いコードは空。
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

        if (! is_array($kind) || ! is_string($type) || ! isset(self::REQUIRED_KEYS[$type])) {
            return ["種類 {$code} の type が prompt・transcription・conversation のどれでもない(config/ai_runs.php の kinds)"];
        }

        $wrong = self::wrongKeys($kind, $type);
        $keys = implode('・', $wrong);
        $problems = $wrong === [] ? [] : ["種類 {$code}({$type})の {$keys} が無いか形が合わない(config/ai_runs.php の kinds)"];

        return [...$problems, ...self::optionProblems($code, $type, $kind)];
    }

    /**
     * 接続先の要求に足す指定の食い違い。
     *
     * @param  array<array-key, mixed>  $kind
     * @return list<string>
     */
    private static function optionProblems(string $code, string $type, array $kind): array
    {
        if ($type === self::CONVERSATION) {
            return array_key_exists('provider_options', $kind)
                ? ["種類 {$code}(conversation)が provider_options を持つ(会話の型は用途ごとに持つ = config/ai_runs.php の conversation)"]
                : [];
        }

        $options = $kind['provider_options'] ?? [];
        $runKey = self::RUN_OPTION_KEY;

        return is_array($options) && array_key_exists($runKey, $options)
            ? ["種類 {$code}({$type})の provider_options が実行ごとに足す鍵 {$runKey} を持つ(config/ai_runs.php の kinds)"]
            : [];
    }

    /**
     * 種類の設定(型ごとの形。type で分ける)。設定の形の確かめは 1 回の呼び出しで 1 回。設定に無いコードなら null。
     *
     * @return PromptKindSettings|TranscriptionKindSettings|ConversationKindSettings|null
     *
     * @throws LogicException 設定の形が合わないとき
     */
    public function settings(string $code): ?array
    {
        $kind = $this->kinds()[$code] ?? null;
        $problems = $this->problems($code);

        if ($problems !== []) {
            throw new LogicException(implode(' / ', $problems));
        }

        return match (is_array($kind) ? $kind['type'] : null) {
            self::PROMPT => self::promptOf((array) $kind, $code),
            self::TRANSCRIPTION => self::transcriptionOf((array) $kind),
            self::CONVERSATION => self::conversationOf((array) $kind),
            default => null,
        };
    }

    /** @return PromptKindSettings|null 文章の種類の設定(設定に無いコード・ほかの型は null。形が合わなければ LogicException) */
    public function prompt(string $code): ?array
    {
        $kind = $this->settings($code);

        return $kind !== null && $kind['type'] === self::PROMPT ? $kind : null;
    }

    /** @return TranscriptionKindSettings|null 文字起こしの種類の設定(設定に無いコード・ほかの型は null。形が合わなければ LogicException) */
    public function transcription(string $code): ?array
    {
        $kind = $this->settings($code);

        return $kind !== null && $kind['type'] === self::TRANSCRIPTION ? $kind : null;
    }

    /** @return ConversationKindSettings|null 会話の型の種類の設定(設定に無いコード・ほかの型は null。形が合わなければ LogicException) */
    public function conversation(string $code): ?array
    {
        $kind = $this->settings($code);

        return $kind !== null && $kind['type'] === self::CONVERSATION ? $kind : null;
    }

    /**
     * @param  array<array-key, mixed>  $kind  形を確かめた設定
     * @return PromptKindSettings
     */
    private static function promptOf(array $kind, string $code): array
    {
        return [
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
     * @param  array<array-key, mixed>  $kind  形を確かめた設定
     * @return TranscriptionKindSettings
     */
    private static function transcriptionOf(array $kind): array
    {
        return [
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
     * @param  array<array-key, mixed>  $kind  形を確かめた設定
     * @return ConversationKindSettings
     */
    private static function conversationOf(array $kind): array
    {
        $purposes = (array) $kind['purposes'];
        $limits = static fn (string $purpose): array => [
            'timeout_seconds' => (int) $purposes[$purpose]['timeout_seconds'],
            'max_output_tokens' => (int) $purposes[$purpose]['max_output_tokens'],
        ];

        return [
            'type' => self::CONVERSATION,
            'budget' => (string) $kind['budget'],
            'max_input_chars' => (int) $kind['max_input_chars'],
            'max_turns' => (int) $kind['max_turns'],
            'run_timeout_seconds' => (int) $kind['run_timeout_seconds'],
            'purposes' => array_combine(
                ConversationSettings::PURPOSES,
                array_map($limits, ConversationSettings::PURPOSES),
            ),
        ];
    }

    /**
     * 型の必須の鍵のうち、無いか形が合わない鍵(文章・文字起こしの接続先の要求に足す指定は任意で、あれば配列。会話の型は用途ごとの鍵を
     * purposes.<用途>.<鍵> の形で返す)。
     *
     * @param  array<array-key, mixed>  $kind
     * @return list<string>
     */
    private static function wrongKeys(array $kind, string $type): array
    {
        $wrong = [];

        foreach (self::REQUIRED_KEYS[$type] as $key => $form) {
            $wrong = self::fits($kind[$key] ?? null, $form) ? $wrong : [...$wrong, $key];
        }

        if ($type === self::CONVERSATION) {
            return [...$wrong, ...self::wrongPurposeKeys($kind['purposes'] ?? null)];
        }

        return is_array($kind['provider_options'] ?? []) ? $wrong : [...$wrong, 'provider_options'];
    }

    /**
     * 会話の型の種類の用途ごとの鍵のうち、無いか形が合わない鍵。
     *
     * @return list<string>
     */
    private static function wrongPurposeKeys(mixed $purposes): array
    {
        $wrong = [];

        foreach (ConversationSettings::PURPOSES as $purpose) {
            $limits = (array) (is_array($purposes) ? ($purposes[$purpose] ?? []) : []);

            foreach (self::PURPOSE_KEYS as $key) {
                if (! self::fits($limits[$key] ?? null, 'int')) {
                    $wrong[] = "purposes.{$purpose}.{$key}";
                }
            }
        }

        return $wrong;
    }

    /** 値が形(空でない文字列 string か正の整数 int)に合うか */
    private static function fits(mixed $value, string $form): bool
    {
        return $form === 'int' ? is_int($value) && $value > 0 : is_string($value) && $value !== '';
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
