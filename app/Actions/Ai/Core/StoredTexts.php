<?php

namespace App\Actions\Ai\Core;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\ConversationItemType;
use Closure;
use LogicException;

/**
 * 道具の結果と中断の印の文を組む中核の約束の部品(docs の 設計/AI/会話の中核.md 13.2。会話の保存に書く時に組むのも、送る時に組み直すのも
 * この 1 つ)。組む前に、組む時の版の会話の印の形の一覧を道具の結果の本文と定型に入れる値に当てる(10.6 の (1))。一覧の印 = 一覧の中身から
 * 決まる値(並びと重なりによらない)。送る時は、項目の印が今の印と同じなら組んだ文をそのまま送り、違うか組んでいなければ組む。
 *
 * @phpstan-import-type TemplateValues from ConversationItem
 */
final readonly class StoredTexts
{
    /** 今の版の一覧の印 */
    public string $formsId;

    /**
     * @param  Closure(AiInstructionTemplate, TemplateValues): string  $template  定型の文を組む(ループが使う文の口か指示の組み立ての口)
     * @param  list<string>  $forms  今の版の会話の印の形の一覧
     */
    public function __construct(private Closure $template, private array $forms)
    {
        $this->formsId = self::formsId($forms);
    }

    /**
     * @param  list<string>  $forms
     */
    public static function formsId(array $forms): string
    {
        $sorted = array_values(array_unique($forms));
        sort($sorted, SORT_STRING);

        return hash('sha256', (string) json_encode($sorted, JSON_UNESCAPED_UNICODE));
    }

    /** 組んだ文と今の印を持たせた項目(道具の結果と中断の印のほかは、そのまま返す) */
    public function withRendered(ConversationItem $item): ConversationItem
    {
        $stores = in_array($item->type, [ConversationItemType::ToolResult, ConversationItemType::Interruption], true);

        if (! $stores) {
            return $item;
        }

        $rendered = ['text' => $this->render($item), 'forms_id' => $this->formsId];
        $content = $item->content;

        return match ($item->type) {
            ConversationItemType::ToolResult => ConversationItem::toolResult(
                $content['call_id'],
                $content['template'] === null ? null : AiInstructionTemplate::from($content['template']),
                $content['values'],
                $content['body'],
                $content['is_error'],
                $rendered,
            ),
            default => ConversationItem::interruption($content['during_tool'], $rendered),
        };
    }

    /** 送る文(組んだ文の印が今の印と同じならその文、違うか組んでいなければ今の版で組む) */
    public function sent(ConversationItem $item): string
    {
        $rendered = $item->content['rendered'] ?? null;

        return is_array($rendered) && $rendered['forms_id'] === $this->formsId
            ? (string) $rendered['text']
            : $this->render($item);
    }

    /**
     * @throws LogicException 道具の結果と中断の印でないとき
     */
    private function render(ConversationItem $item): string
    {
        $content = $item->content;

        return match ($item->type) {
            ConversationItemType::ToolResult => $this->toolResult($content),
            ConversationItemType::Interruption => ($this->template)($content['during_tool'] === true
                ? AiInstructionTemplate::InterruptedForToolUse
                : AiInstructionTemplate::Interrupted, []),
            default => throw new LogicException("項目 {$item->type->value} は組んだ文を持たない"),
        };
    }

    /**
     * @param  array<string, mixed>  $content
     */
    private function toolResult(array $content): string
    {
        $body = is_string($content['body']) ? $this->broken($content['body']) : null;
        /** @var TemplateValues $given */
        $given = $content['values'];
        $values = array_map(
            fn (string|array $value): string|array => is_string($value)
                ? $this->broken($value)
                : array_map($this->broken(...), $value),
            $given,
        );

        if ($content['template'] === null) {
            return $body ?? '';
        }

        $filled = $body === null ? $values : [...$values, AiInstructionTemplate::BODY_VALUE_KEY => $body];

        return ($this->template)(AiInstructionTemplate::from($content['template']), $filled);
    }

    private function broken(string $text): string
    {
        return ConversationMarkers::broken($text, $this->forms);
    }
}
