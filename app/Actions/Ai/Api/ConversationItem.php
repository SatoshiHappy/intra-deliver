<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemDisplay;
use App\Enums\Ai\ConversationItemType;
use LogicException;

/**
 * 会話の保存の項目 1 つ(docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」)。中身の形は ③ の公開の側のここに 1 つ定め、
 * 両方の版(移した版と代わりの版)が同じ形で書き読む。SDK のメッセージの型は使わない(ADR 0039 の決定 2。読むときに SDK の 1 ステップの口に渡す形へ写す)。
 * ① は {@see self::toArray()} の写しを組み替えず書き換えずに持ち、{@see self::fromArray()} で読み戻す(画面に描くために写しを読んでよい)。
 * 形を変えるときは形の版 {@see self::VERSION} を上げ、前の形の項目が会話に残る間は前の形も読む(9.13)。
 * 道具の呼び出しの引数は SDK の道具の呼び出しの型と同じ配列で持つ。写しでは空のオブジェクトと空の配列の区別が消えるが、SDK の型も配列なので
 * 失うものは無く、最上位の空は各接続先の写しがオブジェクトに戻す(入れ子の空のオブジェクトが配列になるのは SDK の型と同じ)。
 * 写しは値と鍵の順のまま持つ(順が変わると接続先のキャッシュが当たらない = docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の守る結果)。
 *
 * @phpstan-type TextBlock array{type: 'text', text: string}
 * @phpstan-type ToolCallBlock array{type: 'tool_call', id: string, name: string, arguments: array<string, mixed>}
 * @phpstan-type UserTextContent array{text: string}
 * @phpstan-type ModelTextContent array{blocks: list<TextBlock|ToolCallBlock>}
 * @phpstan-type TemplateValues array<string, string|list<string>>
 * @phpstan-type ToolResultContent array{call_id: string, template: string|null, values: TemplateValues,
 *     body: string|null, is_error: bool}
 * @phpstan-type InterruptionContent array{during_tool: bool}
 * @phpstan-type CompactSummaryContent array{text: string}
 * @phpstan-type ItemContent UserTextContent|ModelTextContent|ToolResultContent|InterruptionContent
 *     |CompactSummaryContent|array{}
 * @phpstan-type ItemCopy array{type: string, display: string, version: int, content: array<string, mixed>}
 */
final readonly class ConversationItem
{
    /** 今の形の版(道具の結果を定型の種類・値・本文に分けた形。前の版の項目を書く物は無いので、前の版は読まない) */
    public const VERSION = 2;

    /** 理由つきの断りの理由を持つ定型の値の鍵(① は理由を利用者の文として出す) */
    public const USER_FEEDBACK = 'user_feedback';

    /**
     * @param  ItemContent  $content  種類ごとの中身(利用者の文・要約の文 = 文、モデルの文 = 確定したブロックの列、道具の結果 = 呼び出しの id・定型の種類・
     *                                定型に入れる値・本文・失敗の印、中断の印 = 道具の途中か、回数の上限の印・要約の境目 = 空)
     */
    private function __construct(
        public ConversationItemType $type,
        public ConversationItemDisplay $display,
        public int $version,
        public array $content,
    ) {}

    /**
     * 今の形の版の項目。
     *
     * @param  ItemContent  $content
     */
    private static function of(ConversationItemType $type, ConversationItemDisplay $display, array $content): self
    {
        return new self($type, $display, self::VERSION, $content);
    }

    /** 利用者の文(画面の履歴に出す) */
    public static function userText(string $text): self
    {
        return self::of(ConversationItemType::UserText, ConversationItemDisplay::Show, ['text' => $text]);
    }

    /**
     * モデルの文(確定したブロックの列。文のブロックと道具の呼び出しのブロック。画面の履歴に出す)。ブロックは作り手で作り直してから持つ
     * (作った時の写しと読み戻した写しの鍵の順を同じにするため。道具の呼び出しの引数は渡されたまま)。
     *
     * @param  list<TextBlock|ToolCallBlock>  $blocks
     */
    public static function modelText(array $blocks): self
    {
        $rebuilt = array_map(
            static fn (array $block): array => $block['type'] === 'text'
                ? self::textBlock($block['text'])
                : self::toolCallBlock($block['id'], $block['name'], $block['arguments']),
            $blocks,
        );

        return self::of(ConversationItemType::ModelText, ConversationItemDisplay::Show, ['blocks' => $rebuilt]);
    }

    /**
     * 文のブロック({@see self::modelText()} に渡す)。
     *
     * @return TextBlock
     */
    public static function textBlock(string $text): array
    {
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * 道具の呼び出しのブロック({@see self::modelText()} に渡す)。
     *
     * @param  array<string, mixed>  $arguments
     * @return ToolCallBlock
     */
    public static function toolCallBlock(string $id, string $name, array $arguments): array
    {
        return ['type' => 'tool_call', 'id' => $id, 'name' => $name, 'arguments' => $arguments];
    }

    /**
     * 道具の結果(画面の履歴に出さない。① は道具の呼び出しを実行の読み取りの一覧から出す)。組んだ文は持たず、定型の種類・定型に入れる値・本文
     * (長さの上限を当てた後)に分けて持ち、送るときに今の版で組む(9.13)。定型を使わない結果は種類が無しで本文だけ、空の結果は本文が無い。
     * 理由つきの断り(承認待ちへの理由つきの断り = 9.3 の H3)は理由を値の {@see self::USER_FEEDBACK} に持ち、理由を利用者の文として出す。
     *
     * @param  TemplateValues  $values  置き換え口の名前 → 値
     */
    public static function toolResult(
        string $callId,
        ?AiInstructionTemplate $template,
        array $values,
        ?string $body,
        bool $isError,
    ): self {
        $display = isset($values[self::USER_FEEDBACK])
            ? ConversationItemDisplay::ReasonAsUserText
            : ConversationItemDisplay::Hide;

        return self::of(ConversationItemType::ToolResult, $display, [
            'call_id' => $callId,
            'template' => $template?->value,
            'values' => $values,
            'body' => $body,
            'is_error' => $isError,
        ]);
    }

    /** 中断の印(道具の途中か否かの 2 つの形。画面の履歴に出す) */
    public static function interruption(bool $duringTool): self
    {
        $content = ['during_tool' => $duringTool];

        return self::of(ConversationItemType::Interruption, ConversationItemDisplay::Show, $content);
    }

    /** 回数の上限の印(画面の履歴に出し、モデルには送らない = 9.7) */
    public static function turnLimit(): self
    {
        return self::of(ConversationItemType::TurnLimit, ConversationItemDisplay::Show, []);
    }

    /** 要約の境目(画面の履歴に境目の 1 行として出し、モデルには送らない = 9.9) */
    public static function compactBoundary(): self
    {
        return self::of(ConversationItemType::CompactBoundary, ConversationItemDisplay::BoundaryLine, []);
    }

    /** 要約の文(画面の履歴に出さない = 9.9) */
    public static function compactSummary(string $text): self
    {
        return self::of(ConversationItemType::CompactSummary, ConversationItemDisplay::Hide, ['text' => $text]);
    }

    /**
     * ① が持つ形への写し。
     *
     * @return ItemCopy
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'display' => $this->display->value,
            'version' => $this->version,
            'content' => $this->content,
        ];
    }

    /**
     * 写しからの読み。
     *
     * @param  array<array-key, mixed>  $copy  {@see self::toArray()} の写し
     *
     * @throws LogicException 知らない形の版・種類・出し方か、中身が種類の形に合わないとき(作りの誤り)
     */
    public static function fromArray(array $copy): self
    {
        $version = $copy['version'] ?? null;

        if ($version !== self::VERSION) {
            throw new LogicException('会話の保存の項目の形の版 '.json_encode($version).' を知らない(読めるのは '.self::VERSION.')');
        }

        $type = ConversationItemType::tryFrom(is_string($copy['type'] ?? null) ? $copy['type'] : '');
        $display = ConversationItemDisplay::tryFrom(is_string($copy['display'] ?? null) ? $copy['display'] : '');
        $content = $copy['content'] ?? null;

        if ($type === null || $display === null || ! is_array($content) || ! self::fits($type, $content)) {
            throw new LogicException('会話の保存の項目の種類・出し方・中身の形が合わない');
        }

        $item = self::rebuilt($type, $content);

        if ($item->display !== $display) {
            throw new LogicException("会話の保存の項目の出し方 {$display->value} が種類の中身と合わない");
        }

        return $item;
    }

    /**
     * 形を確かめた中身から、作り手で作り直す(写しを持つ側が鍵の順を変えても、同じ値になる。モデルの文のブロックは {@see self::modelText()} が作り直す)。
     *
     * @param  array<array-key, mixed>  $content
     */
    private static function rebuilt(ConversationItemType $type, array $content): self
    {
        return match ($type) {
            ConversationItemType::UserText => self::userText($content['text']),
            ConversationItemType::ModelText => self::modelText(array_values($content['blocks'])),
            ConversationItemType::ToolResult => self::toolResult(
                $content['call_id'],
                $content['template'] === null ? null : AiInstructionTemplate::from($content['template']),
                $content['values'],
                $content['body'],
                $content['is_error'],
            ),
            ConversationItemType::Interruption => self::interruption($content['during_tool']),
            ConversationItemType::TurnLimit => self::turnLimit(),
            ConversationItemType::CompactBoundary => self::compactBoundary(),
            ConversationItemType::CompactSummary => self::compactSummary($content['text']),
        };
    }

    /**
     * 中身が種類の形に合うか。
     *
     * @param  array<array-key, mixed>  $content
     */
    private static function fits(ConversationItemType $type, array $content): bool
    {
        return match ($type) {
            ConversationItemType::UserText,
            ConversationItemType::CompactSummary => self::hasOnly($content, ['text' => 'string']),
            ConversationItemType::ModelText => self::hasOnly($content, ['blocks' => 'array'])
                && self::blocksFit($content['blocks']),
            ConversationItemType::ToolResult => self::hasOnly($content, [
                'call_id' => 'string',
                'template' => '?template',
                'values' => 'values',
                'body' => '?string',
                'is_error' => 'bool',
            ]),
            ConversationItemType::Interruption => self::hasOnly($content, ['during_tool' => 'bool']),
            ConversationItemType::TurnLimit, ConversationItemType::CompactBoundary => $content === [],
        };
    }

    /** モデルの文のブロックの列が、文のブロックと道具の呼び出しのブロックだけか */
    private static function blocksFit(mixed $blocks): bool
    {
        if (! is_array($blocks) || ! array_is_list($blocks)) {
            return false;
        }

        foreach ($blocks as $block) {
            $fits = is_array($block) && match ($block['type'] ?? null) {
                'text' => self::hasOnly($block, ['type' => 'string', 'text' => 'string']),
                'tool_call' => self::hasOnly(
                    $block,
                    ['type' => 'string', 'id' => 'string', 'name' => 'string', 'arguments' => 'array'],
                ),
                default => false,
            };

            if (! $fits) {
                return false;
            }
        }

        return true;
    }

    /**
     * 配列が渡した鍵だけを持ち(順は問わない)、値が形(string・?string・bool・array・?template = 定型の種類の値か null・values = 定型の値)に
     * 合うか。
     *
     * @param  array<array-key, mixed>  $values
     * @param  array<string, string>  $forms
     */
    private static function hasOnly(array $values, array $forms): bool
    {
        if (count($values) !== count($forms) || array_diff_key($forms, $values) !== []) {
            return false;
        }

        foreach ($forms as $key => $form) {
            $value = $values[$key];
            $fits = match ($form) {
                'string' => is_string($value),
                '?string' => $value === null || is_string($value),
                'bool' => is_bool($value),
                '?template' => $value === null
                    || (is_string($value) && AiInstructionTemplate::tryFrom($value) !== null),
                'values' => self::valuesFit($value),
                default => is_array($value),
            };

            if (! $fits) {
                return false;
            }
        }

        return true;
    }

    /** 定型の値が、文字列の鍵 → 文字列か文字列の列か */
    private static function valuesFit(mixed $values): bool
    {
        if (! is_array($values)) {
            return false;
        }

        foreach ($values as $key => $value) {
            $fits = is_string($key) && (is_string($value) || (is_array($value) && array_is_list($value)
                && array_filter($value, is_string(...)) === $value));

            if (! $fits) {
                return false;
            }
        }

        return true;
    }
}
