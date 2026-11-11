<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * AI の実行の種類の設定 1 つの形の規則(型と型ごとの必須の鍵と接続先の要求に足す指定)と、形の合う設定の組み立て(内部。読む 1 か所 = {@see KindSettings})。
 * 文章・文字起こしの足す指定は {@see KindSettings::RUN_OPTION_KEY} を持たない(持つと、実行ごとに混ぜる値と混ぜる順で片方が消える)。会話の型の種類は
 * 足す指定を持たない(用途ごとに持つ = docs の 設計/AI/実行と費用/README.md 4.4)。
 *
 * @phpstan-import-type AnyKindSettings from KindSettings
 * @phpstan-import-type PromptKindSettings from KindSettings
 * @phpstan-import-type TranscriptionKindSettings from KindSettings
 * @phpstan-import-type ConversationKindSettings from KindSettings
 */
final class KindShape
{
    /** 型ごとの必須の鍵(値は空でない文字列 string か正の整数 int。予算の区分はどの型にもある) */
    private const REQUIRED_KEYS = [
        KindSettings::PROMPT => [
            'provider' => 'string', 'model' => 'string', 'budget' => 'string', 'timeout_seconds' => 'int',
            'max_output_tokens' => 'int', 'max_input_chars' => 'int',
        ],
        KindSettings::TRANSCRIPTION => [
            'provider' => 'string', 'model' => 'string', 'budget' => 'string', 'timeout_seconds' => 'int',
            'language' => 'string', 'max_audio_seconds' => 'int', 'chunk_seconds' => 'int',
        ],
        KindSettings::CONVERSATION => [
            'budget' => 'string', 'max_input_chars' => 'int', 'max_turns' => 'int', 'run_timeout_seconds' => 'int',
        ],
    ];

    /** 会話の型の種類が用途({@see ConversationSettings::PURPOSES})ごとに持つ必須の鍵(正の整数) */
    private const PURPOSE_KEYS = ['timeout_seconds', 'max_output_tokens'];

    /**
     * 種類 $code の設定 $kind の形の食い違い(型・型ごとの必須の鍵・足す指定の鍵)。無ければ空。
     *
     * @return list<string>
     */
    public static function problems(string $code, mixed $kind): array
    {
        $type = is_array($kind) ? ($kind['type'] ?? null) : null;

        if (! is_array($kind) || ! is_string($type) || ! isset(self::REQUIRED_KEYS[$type])) {
            return ["種類 {$code} の type が prompt・transcription・conversation のどれでもない(config/ai_runs.php の kinds)"];
        }

        $wrong = self::wrongKeys($kind, $type);
        $keys = implode('・', $wrong);
        $problems = $wrong === [] ? [] : ["種類 {$code}({$type})の {$keys} が無いか形が合わない(config/ai_runs.php の kinds)"];

        return [...$problems, ...self::optionProblems($code, $type, $kind)];
    }

    /** 設定 $kind の予算の区分(空でない文字列でなければ null。形の合わない設定でも読む) */
    public static function budgetOf(mixed $kind): ?string
    {
        $budget = is_array($kind) ? ($kind['budget'] ?? null) : null;

        return is_string($budget) && $budget !== '' ? $budget : null;
    }

    /**
     * 形を確かめた設定 $kind({@see self::problems()} が空)の、型ごとの形。
     *
     * @param  array<array-key, mixed>  $kind
     * @return AnyKindSettings
     *
     * @throws LogicException 形が合わないとき(作りの誤り)
     */
    public static function of(string $code, array $kind): array
    {
        return match ($kind['type'] ?? null) {
            KindSettings::PROMPT => self::promptOf($kind, $code),
            KindSettings::TRANSCRIPTION => self::transcriptionOf($kind),
            KindSettings::CONVERSATION => self::conversationOf($kind),
            default => throw new LogicException(implode(' / ', self::problems($code, $kind))),
        };
    }

    /**
     * 接続先の要求に足す指定の食い違い。
     *
     * @param  array<array-key, mixed>  $kind
     * @return list<string>
     */
    private static function optionProblems(string $code, string $type, array $kind): array
    {
        if ($type === KindSettings::CONVERSATION) {
            return array_key_exists('provider_options', $kind)
                ? ["種類 {$code}(conversation)が provider_options を持つ(会話の型は用途ごとに持つ = config/ai_runs.php の conversation)"]
                : [];
        }

        $options = $kind['provider_options'] ?? [];
        $runKey = KindSettings::RUN_OPTION_KEY;

        if (! is_array($options)) {
            return [];
        }

        $stringKeyed = array_all(array_keys($options), fn (int|string $key): bool => is_string($key));

        return [
            ...(array_key_exists($runKey, $options)
                ? ["種類 {$code}({$type})の provider_options が実行ごとに足す鍵 {$runKey} を持つ(config/ai_runs.php の kinds)"]
                : []),
            ...($stringKeyed ? [] : [self::notStringKeyed($code)]),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $kind  形を確かめた設定
     * @return PromptKindSettings
     */
    private static function promptOf(array $kind, string $code): array
    {
        return [
            'type' => KindSettings::PROMPT,
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
            'type' => KindSettings::TRANSCRIPTION,
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
            'type' => KindSettings::CONVERSATION,
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

        if ($type === KindSettings::CONVERSATION) {
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

        foreach ($values as $key => $value) {
            $keyed[is_string($key) ? $key : throw new LogicException(self::notStringKeyed($code))] = $value;
        }

        return $keyed;
    }

    /** 接続先の要求に足す指定の鍵が文字列でないときの文 */
    private static function notStringKeyed(string $code): string
    {
        return "config/ai_runs.php の kinds.{$code}.provider_options の鍵が文字列でない";
    }
}
