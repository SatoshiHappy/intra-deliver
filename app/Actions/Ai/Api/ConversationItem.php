<?php

namespace App\Actions\Ai\Api;

use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemDisplay;
use App\Enums\Ai\ConversationItemType;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

/**
 * 会話の保存の項目 1 つ(docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」)。中身の形は ③ の公開の側のここに 1 つ定め、
 * 両方の版(移した版と代わりの版)が同じ形で書き読む。SDK のメッセージの型は使わない(ADR 0039 の決定 2。読むときに SDK の 1 ステップの口に渡す形へ写す)。
 * ① は {@see self::toArray()} の写しを組み替えず書き換えずに持ち、{@see self::fromArray()} で読み戻す(画面に描くために写しを読んでよい)。
 * 形の版は、会話の保存を配った後の形の変更に当てる(変えるときは {@see self::VERSION} を上げ、前の形の項目が会話に残る間は前の形も読む = 9.13)。
 * 道具の呼び出しの引数は SDK の道具の呼び出しの型と同じ配列で持つ。写しでは空のオブジェクトと空の配列の区別が消えるが、SDK の型も配列なので
 * 失うものは無く、最上位の空は各接続先の写しがオブジェクトに戻す(入れ子の空のオブジェクトが配列になるのは SDK の型と同じ)。
 * 写しは値と鍵の順のまま持つ(順が変わると接続先のキャッシュが当たらない = docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の守る結果)。
 * どの作り手も作る時に形を確かめ、合わなければ作りの誤り(LogicException)。
 *
 * @phpstan-type TextBlock array{type: 'text', text: string}
 * @phpstan-type ToolCallBlock array{type: 'tool_call', id: string, name: string, arguments: array<string, mixed>,
 *     result_id: string|null}
 * @phpstan-type TemplateValues array<string, string|list<string>>
 * @phpstan-type ToolResultContent array{call_id: string, template: string|null, values: TemplateValues,
 *     body: string|null, is_error: bool}
 * @phpstan-type Usage array{input_tokens: int, output_tokens: int, cache_read_input_tokens: int|null,
 *     cache_write_input_tokens: int|null, reasoning_tokens: int|null}
 * @phpstan-type ItemCopy array{type: string, display: string, version: int, content: array<string, mixed>}
 */
final readonly class ConversationItem
{
    /** 今の形の版(会話の保存を配る前なので、13 節の種類と欄を含む今の形を最初の版とし、値は前のまま = 9.13) */
    public const VERSION = 2;

    /** 理由つきの断りの理由を持つ定型の値の鍵(① は理由を利用者の文として出す) */
    public const USER_FEEDBACK = 'user_feedback';

    /** 要約の境目が持つ日の形(東京の日。先頭の日付の文の値 = docs の 設計/AI/会話の中核.md 13.2) */
    public const DATE_FORMAT = 'Y-m-d';

    /**
     * @param  array<string, mixed>  $content  種類ごとの中身(形 = {@see ConversationItemShape::fits()})
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
     * @param  array<string, mixed>  $content
     */
    private static function of(ConversationItemType $type, ConversationItemDisplay $display, array $content): self
    {
        if (! ConversationItemShape::fits($type, $content)) {
            throw new LogicException("会話の保存の項目 {$type->value} の中身が種類の形に合わない");
        }

        return new self($type, $display, self::VERSION, $content);
    }

    /** 利用者の文(画面の履歴に出す) */
    public static function userText(string $text): self
    {
        return self::of(ConversationItemType::UserText, ConversationItemDisplay::Show, ['text' => $text]);
    }

    /**
     * モデルの文(1 つの応答の確定したブロック 1 つか、同じ時に確定した物。会話の中核が振る応答の id で束ねる = docs の 設計/AI/会話の中核.md 13.2。
     * 画面の履歴に出す)。ブロックは作り手で作り直してから持つ(作った時の写しと読み戻した写しの鍵の順を同じにするため。引数は渡されたまま)。
     *
     * @param  list<TextBlock|ToolCallBlock>  $blocks
     */
    public static function modelText(string $responseId, array $blocks): self
    {
        $rebuilt = array_map(
            static fn (array $block): array => $block['type'] === 'text'
                ? self::textBlock($block['text'])
                : self::toolCallBlock($block['id'], $block['name'], $block['arguments'], $block['result_id']),
            $blocks,
        );

        return self::of(
            ConversationItemType::ModelText,
            ConversationItemDisplay::Show,
            ['response_id' => $responseId, 'blocks' => $rebuilt],
        );
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
     * 道具の呼び出しのブロック({@see self::modelText()} に渡す)。$id = 会話の中で呼び出しを指す id(SDK の `id`)。$resultId = SDK の結果を結ぶ id
     * (SDK の `ToolCall` の `resultId`。無い接続先は null。送る形への変換で結果に付ける = 設計/AI/エージェントの実行/口と契約.md 9.13)。
     *
     * @param  array<string, mixed>  $arguments
     * @return ToolCallBlock
     */
    public static function toolCallBlock(string $id, string $name, array $arguments, ?string $resultId = null): array
    {
        return [
            'type' => 'tool_call',
            'id' => $id,
            'name' => $name,
            'arguments' => $arguments,
            'result_id' => $resultId,
        ];
    }

    /**
     * 応答の終わり(画面の履歴に出さない。書くのは会話の中核が応答の終わりを知らせた応答だけ = docs の 設計/AI/会話の中核の口と受け手.md 13.3)。
     * $rawState = 接続先の生の状態(思考と署名を含む、接続先が返したブロックの列。開けない値。空の id に UUID を振った応答では null)。
     * $model = その送信に中核が渡したモデルの値(SDK の応答のモデルの値でない = 設計/AI/会話の中核.md 13.2)。$callIds = 接続先が返したままの呼び出しの id の列。
     *
     * @param  Usage  $usage
     * @param  array<array-key, mixed>|null  $rawState
     * @param  list<string>  $callIds
     */
    public static function responseEnd(
        string $responseId,
        array $usage,
        ?array $rawState,
        string $provider,
        string $model,
        int $blockCount,
        array $callIds,
        DateTimeInterface $finishedAt,
    ): self {
        return self::of(ConversationItemType::ResponseEnd, ConversationItemDisplay::Hide, [
            'response_id' => $responseId,
            'usage' => ConversationItemShape::orderedUsage($usage),
            'raw_state' => $rawState,
            'provider' => $provider,
            'model' => $model,
            'block_count' => $blockCount,
            'call_ids' => $callIds,
            'finished_at' => $finishedAt->format(DATE_ATOM),
        ]);
    }

    /** 取り消しの印(応答の id。前の試しの応答を送らず出さない物にする = 設計/AI/エージェントの実行/口と契約.md 9.13。画面の履歴に出さない) */
    public static function cancellation(string $responseId): self
    {
        $content = ['response_id' => $responseId];

        return self::of(ConversationItemType::Cancellation, ConversationItemDisplay::Hide, $content);
    }

    /**
     * 道具の結果(持つ物と出し方 = 9.13 の項目の種類)。理由つきの断りは、理由を値の {@see self::USER_FEEDBACK} に持つ。
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

    /** 回数の上限の印(画面の履歴に出し、モデルには送らない = 設計/AI/エージェントの実行/途中の表示と上限.md 9.7) */
    public static function turnLimit(): self
    {
        return self::of(ConversationItemType::TurnLimit, ConversationItemDisplay::Show, []);
    }

    /**
     * 足す文(日付が変わった知らせ・結果の分からない書き込みの知らせ。画面の履歴に出さない。足す時と所 = docs の 設計/AI/会話の中核.md 13.2)。
     * 組んだ文は持たず、定型の種類と入れる値を持ち、送るときに今の版で組む。$callIds = 結果の分からない書き込みの知らせが知らせたすべての
     * 呼び出しの id(画面にもモデルにも出さない値 = 設計/AI/エージェントの実行/途中の表示と上限.md 9.8。日付が変わった知らせは空)。
     *
     * @param  TemplateValues  $values
     * @param  list<string>  $callIds
     */
    public static function addedText(AiInstructionTemplate $template, array $values, array $callIds): self
    {
        return self::of(ConversationItemType::AddedText, ConversationItemDisplay::Hide, [
            'template' => $template->value,
            'values' => $values,
            'call_ids' => $callIds,
        ]);
    }

    /**
     * 要約の境目(画面の履歴に境目の 1 行として出し、モデルには送らない = 9.9)。$estimate = 要約の前の送る量の見積もり(① が使えるように持つ)。
     * $date = 要約が済んだ時の時計の口の今日({@see self::DATE_FORMAT}。先頭の日付の文の値 = 設計/AI/会話の中核.md 13.2)。
     */
    public static function compactBoundary(int $estimate, string $date): self
    {
        $content = ['estimate' => $estimate, 'date' => $date];

        return self::of(ConversationItemType::CompactBoundary, ConversationItemDisplay::BoundaryLine, $content);
    }

    /** 要約の文(画面の履歴に出さない = 設計/AI/エージェントの実行/途中の表示と上限.md 9.9) */
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

        $fits = $type !== null && is_array($content) && ConversationItemShape::fits($type, $content);

        if ($display === null || ! $fits) {
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
            ConversationItemType::ModelText => self::modelText(
                $content['response_id'],
                array_values($content['blocks']),
            ),
            ConversationItemType::ResponseEnd => self::responseEnd(
                $content['response_id'],
                $content['usage'],
                $content['raw_state'],
                $content['provider'],
                $content['model'],
                $content['block_count'],
                $content['call_ids'],
                new DateTimeImmutable($content['finished_at']),
            ),
            ConversationItemType::Cancellation => self::cancellation($content['response_id']),
            ConversationItemType::ToolResult => self::toolResult(
                $content['call_id'],
                $content['template'] === null ? null : AiInstructionTemplate::from($content['template']),
                $content['values'],
                $content['body'],
                $content['is_error'],
            ),
            ConversationItemType::Interruption => self::interruption($content['during_tool']),
            ConversationItemType::TurnLimit => self::turnLimit(),
            ConversationItemType::AddedText => self::addedText(
                AiInstructionTemplate::from($content['template']),
                $content['values'],
                $content['call_ids'],
            ),
            ConversationItemType::CompactBoundary => self::compactBoundary($content['estimate'], $content['date']),
            ConversationItemType::CompactSummary => self::compactSummary($content['text']),
        };
    }
}
