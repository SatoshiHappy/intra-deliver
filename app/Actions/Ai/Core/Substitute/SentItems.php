<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\LoopTexts;
use App\Actions\Ai\Core\StoredTexts;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;

/**
 * 項目 1 つを送る形の文にする(docs の 設計/AI/会話の中核.md 13.2: 道具の結果と中断の印は組んだ文の印が今の印と同じならその文、違えば
 * 今の版で組み直す = {@see StoredTexts}。足す文・中核の中の値の型は今の版の文で組む)。送らない項目(要約の境目・回数の上限の印・取り消しの印・
 * 応答の終わり)と、モデルの文(応答ごとに束ねる = {@see SentHistory})は扱わない。
 */
final readonly class SentItems
{
    private StoredTexts $stored;

    public function __construct(private LoopTexts $texts)
    {
        $this->stored = new StoredTexts($texts->template(...), $texts->markerForms());
    }

    /** 利用者の側の文 1 つ(送らない項目は無し) */
    public function message(ConversationItem|TransientText $item): ?SentMessage
    {
        if ($item instanceof TransientText) {
            $text = $this->texts->template($item->template, []);

            return $item->isFromModel() ? SentMessage::model($text) : SentMessage::user($text);
        }

        return match ($item->type) {
            ConversationItemType::UserText, ConversationItemType::CompactSummary => SentMessage::user(
                (string) $item->content['text'],
            ),
            ConversationItemType::AddedText => SentMessage::user($this->addedText($item)),
            ConversationItemType::Interruption => SentMessage::user($this->stored->sent($item)),
            ConversationItemType::ToolResult => new SentMessage(false, [[
                'type' => 'tool_result',
                'call_id' => (string) $item->content['call_id'],
                'text' => $this->stored->sent($item),
                'is_error' => $item->content['is_error'] === true,
            ]]),
            default => null,
        };
    }

    private function addedText(ConversationItem $item): string
    {
        /** @var array{template: string, values: array<string, string|list<string>>} $content */
        $content = $item->content;

        return $this->texts->template(AiInstructionTemplate::from($content['template']), $content['values']);
    }
}
