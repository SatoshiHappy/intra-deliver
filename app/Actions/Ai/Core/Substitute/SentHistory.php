<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\LoopTexts;
use App\Actions\Ai\Core\SendTarget;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;

/**
 * 代わりの版の送る履歴の組み立て(docs の 設計/AI/会話の中核.md 13.2): 送らない項目を外し、同じ応答の id のモデルの文を 1 つに束ね、
 * 生の状態を渡すかを応答ごとに決め、送る前の対の直し({@see SentPairs})を当てる。指示の組み立ての口が返した先頭と末尾の文を前後に置く。
 *
 * @phpstan-import-type SentBlock from SentMessage
 */
final readonly class SentHistory
{
    private SentItems $items;

    public function __construct(private LoopTexts $texts, private SendTarget $target)
    {
        $this->items = new SentItems($texts);
    }

    /**
     * @param  list<ConversationItem|TransientText>  $history
     * @param  list<string>  $atStart  送る履歴の先頭に置く文(先頭の日付の文など)
     * @param  list<string>  $atEnd  送る履歴の末尾に置く文(要約の依頼の文)
     * @return list<SentMessage>
     */
    public function build(array $history, array $atStart = [], array $atEnd = []): array
    {
        $ends = self::ends($history);
        $messages = array_map(SentMessage::user(...), $atStart);
        $model = null;
        $count = 0;

        foreach ($history as $item) {
            if ($model !== null && ! self::continues($model, $item)) {
                $messages[] = $this->withReplay($model, $count, $ends);
                [$model, $count] = [null, 0];
            }

            if ($item instanceof ConversationItem && $item->type === ConversationItemType::ModelText) {
                $model = self::joined($model, $item);
                $count++;

                continue;
            }

            $message = $this->items->message($item);

            if ($message !== null) {
                $messages[] = $message;
            }
        }

        if ($model !== null) {
            $messages[] = $this->withReplay($model, $count, $ends);
        }

        $missing = $this->texts->template(AiInstructionTemplate::ToolResultMissing, []);

        return SentPairs::paired([...$messages, ...array_map(SentMessage::user(...), $atEnd)], $missing);
    }

    /**
     * 応答の id → 応答の終わりの項目の中身。
     *
     * @param  list<ConversationItem|TransientText>  $history
     * @return array<string, array<string, mixed>>
     */
    private static function ends(array $history): array
    {
        $ends = [];

        foreach ($history as $item) {
            if ($item instanceof ConversationItem && $item->type === ConversationItemType::ResponseEnd) {
                $ends[(string) $item->content['response_id']] = $item->content;
            }
        }

        return $ends;
    }

    /** 束ねているモデルの文に、次の項目が続くか(同じ応答の id のモデルの文と、送らない応答の終わり) */
    private static function continues(SentMessage $model, ConversationItem|TransientText $item): bool
    {
        if (! $item instanceof ConversationItem) {
            return false;
        }

        return match ($item->type) {
            ConversationItemType::ModelText => $item->content['response_id'] === $model->responseId,
            ConversationItemType::ResponseEnd => $item->content['response_id'] === $model->responseId,
            default => false,
        };
    }

    /** モデルの文の項目を束ねに足す(初めの項目なら束ねを作る) */
    private static function joined(?SentMessage $model, ConversationItem $item): SentMessage
    {
        /** @var list<SentBlock> $blocks */
        $blocks = $item->content['blocks'];
        $responseId = (string) $item->content['response_id'];

        return new SentMessage(true, [...($model->blocks ?? []), ...$blocks], $responseId);
    }

    /**
     * 束ねたモデルの文に、渡すなら生の状態を付ける(項目の数と呼び出しの id の列が応答の終わりと合い、作った接続先とモデルが送る先と同じ
     * 応答だけ = 13.2)。
     *
     * @param  int  $count  束ねたモデルの文の項目の数
     * @param  array<string, array<string, mixed>>  $ends
     */
    private function withReplay(SentMessage $model, int $count, array $ends): SentMessage
    {
        $end = $ends[(string) $model->responseId] ?? [];
        $raw = $end['raw_state'] ?? null;
        $passes = is_array($raw)
            && ($end['block_count'] ?? null) === $count
            && ($end['call_ids'] ?? null) === $model->callIds()
            && ($end['provider'] ?? null) === $this->target->providerName
            && ($end['model'] ?? null) === $this->target->model->id;

        return $model->withReplay($passes ? $raw : null);
    }
}
