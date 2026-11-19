<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Api\ConversationItem;
use App\Actions\Ai\Core\ConversationMarkers;
use App\Actions\Ai\Core\LoopTexts;
use App\Actions\Ai\Core\TransientText;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;

/**
 * 項目 1 つを送る形の文にする(docs の 設計/AI/会話の中核.md 13.2: 道具の結果・中断の印・足す文・中核の中の値の型は今の版の文で組み、
 * 会話の印の形の直しは道具の結果の本文と定型に入れる値に当てる)。送らない項目(要約の境目・回数の上限の印・取り消しの印・応答の終わり)と、
 * モデルの文(応答ごとに束ねる = {@see SentHistory})は扱わない。
 *
 * @phpstan-import-type ToolResultContent from ConversationItem
 */
final readonly class SentItems
{
    /** @var list<string> */
    private array $forms;

    public function __construct(private LoopTexts $texts)
    {
        $this->forms = $texts->markerForms();
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
            ConversationItemType::Interruption => SentMessage::user($this->texts->template(
                $item->content['during_tool'] === true
                    ? AiInstructionTemplate::InterruptedForToolUse
                    : AiInstructionTemplate::Interrupted,
                [],
            )),
            ConversationItemType::ToolResult => new SentMessage(false, [$this->toolResult($item)]),
            default => null,
        };
    }

    /**
     * 道具の結果のブロック。
     *
     * @return array{type: 'tool_result', call_id: string, text: string, is_error: bool}
     */
    private function toolResult(ConversationItem $item): array
    {
        /** @var ToolResultContent $content */
        $content = $item->content;
        $body = $content['body'] === null ? null : ConversationMarkers::broken($content['body'], $this->forms);
        $values = array_map(
            fn (string|array $value): string|array => is_string($value)
                ? ConversationMarkers::broken($value, $this->forms)
                : array_map(fn (string $part): string => ConversationMarkers::broken($part, $this->forms), $value),
            $content['values'],
        );
        $template = $content['template'] === null ? null : AiInstructionTemplate::from($content['template']);
        $text = $template === null
            ? (string) $body
            : $this->texts->template($template, $body === null
                ? $values
                : [...$values, AiInstructionTemplate::BODY_VALUE_KEY => $body]);

        return [
            'type' => 'tool_result',
            'call_id' => $content['call_id'],
            'text' => $text,
            'is_error' => $content['is_error'],
        ];
    }

    private function addedText(ConversationItem $item): string
    {
        /** @var array{template: string, values: array<string, string|list<string>>} $content */
        $content = $item->content;

        return $this->texts->template(AiInstructionTemplate::from($content['template']), $content['values']);
    }
}
