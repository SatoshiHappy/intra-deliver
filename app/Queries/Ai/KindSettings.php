<?php

namespace App\Queries\Ai;

use LogicException;

/**
 * AI の実行の種類の設定(config/ai_runs.php の kinds。docs の 設計/AI/実行と費用/接続先と費用.md 5 節)を読む 1 か所。
 * 形が合わない設定は、値を使う所({@see self::settings()} など)では作りの誤り(LogicException)、起動の時の確かめ({@see self::all()})では
 * 食い違いの文(ほかの確かめを続ける)。形の規則は {@see KindShape}。
 * 種類のコードは . を含むので、config() の . の区切りを通さず kinds の配列から引く。必須の鍵は種類の型(文章・文字起こし・会話)ごとに違い、
 * 型は鍵の有無で推さず type に書く(起動の時に {@see self::all()} で確かめる = 設計/AI/データと失敗と検査.md 8 節)。
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
 * @phpstan-type AllKindSettings array{array<string, AnyKindSettings>, list<string>, list<string|null>}
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

    /** 種類の設定の表が配列でないときの文 */
    private const NOT_TABLE = 'config/ai_runs.php の kinds が配列でない';

    /**
     * 設定にある種類のコード。型を渡すと、その型の種類だけ。設定の表が配列でなければ空(その食い違いは {@see self::all()} が文にする)。
     *
     * @return list<string>
     */
    public function codes(?string $type = null): array
    {
        $codes = [];
        $kinds = config('ai_runs.kinds');

        foreach (is_array($kinds) ? $kinds : [] as $code => $kind) {
            if ($type === null || (is_array($kind) && ($kind['type'] ?? null) === $type)) {
                $codes[] = (string) $code;
            }
        }

        return $codes;
    }

    /**
     * 全部の種類の、形の合う設定(コード → 設定)と、形の食い違いの文と、形の合わない種類の予算の区分(空でない文字列でなければ null =
     * どの区分か分からない)。各コードの形の確かめは 1 回。区分を返すのは、形の合わない種類が属する区分の確かめだけを止めるため
     * (docs の 設計/AI/データと失敗と検査.md 8 節)。設定の表が配列でなければ、設定は空で、その文と分からない区分 1 つ。
     *
     * @return AllKindSettings
     */
    public function all(): array
    {
        $kinds = config('ai_runs.kinds');

        if (! is_array($kinds)) {
            return [[], [self::NOT_TABLE], [null]];
        }

        $settings = [];
        $problems = [];
        $budgets = [];

        foreach ($kinds as $code => $kind) {
            [$checked, $shape] = $this->checked((string) $code, $kind);
            $problems = [...$problems, ...$shape];

            if ($checked !== null) {
                $settings[(string) $code] = $checked;
            } else {
                $budgets[] = KindShape::budgetOf($kind);
            }
        }

        return [$settings, $problems, $budgets];
    }

    /**
     * 種類の設定と、形の食い違い(形が合えば設定と空、合わなければ null と食い違いの文。形の確かめは 1 回。形の規則 = {@see KindShape})。
     *
     * @return array{AnyKindSettings, list<never>}|array{null, non-empty-list<string>}
     */
    private function checked(string $code, mixed $kind): array
    {
        $problems = KindShape::problems($code, $kind);

        return $problems === [] ? [KindShape::of($code, (array) $kind), []] : [null, $problems];
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
        $kinds = $this->kinds();

        if (! array_key_exists($code, $kinds)) {
            return null;
        }

        [$kind, $problems] = $this->checked($code, $kinds[$code]);

        return $kind ?? throw new LogicException(implode(' / ', $problems));
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
     * @return array<array-key, mixed>
     */
    private function kinds(): array
    {
        $kinds = config('ai_runs.kinds');

        return is_array($kinds) ? $kinds : throw new LogicException(self::NOT_TABLE);
    }
}
