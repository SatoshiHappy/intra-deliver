<?php

namespace App\Actions\Ai\ToolCalls;

use App\Actions\Ai\Api\ConversationItem;
use App\Enums\Ai\AiInstructionTemplate;
use App\Enums\Ai\AiToolCallStatus;
use App\Enums\Ai\AiToolWriteDenial;
use App\Models\Ai\AiToolCall;
use App\Queries\Ai\Api\RecordMarkers;

/**
 * 書く時の部品の、書かずに終える件の結果と行(docs の 設計/AI/実行の記録.md 2.4 の T7。結果の形は呼ばれた時の包みの結果と同じ =
 * 設計/AI/エージェントの実行/道具の包みと止める.md 9.4)。会話が無ければ項目は null。
 */
class ToolWriteResults
{
    public function __construct(
        private readonly ToolCallRows $rows,
        private readonly ToolResultItems $items,
    ) {}

    /** 登録から外した道具(無い道具の失敗の結果) */
    public function unknown(AnswerRunContext $step, AiToolCall $row): ?ConversationItem
    {
        $values = ['tool_name' => self::name($row)];
        $item = ToolResultItems::failure($row->call_id, AiInstructionTemplate::UnknownTool, $values);

        return $this->finish($step, $row, AiToolCallStatus::ToolError, null, $item);
    }

    /**
     * やり直しの誤り(引数の形の誤りは形の誤り、道具の検証の誤りは入力の誤り。呼ばれた時の B11・B1 と同じ結果)。
     *
     * @param  list<string>  $shape
     */
    public function invalid(AnswerRunContext $step, AiToolCall $row, array $shape, ?string $error): ?ConversationItem
    {
        [$denial, $template, $values] = $shape !== []
            ? [AiToolWriteDenial::InvalidShape, AiInstructionTemplate::ToolInputInvalid, [
                'tool_name' => self::name($row),
                'problems' => array_map(RecordMarkers::neutralize(...), $shape),
            ]]
            : [AiToolWriteDenial::InvalidInput, AiInstructionTemplate::ToolError, [
                'error' => RecordMarkers::neutralize((string) $error),
            ]];
        $item = ToolResultItems::failure($row->call_id, $template, $values);

        return $this->finish($step, $row, AiToolCallStatus::ToolError, $denial, $item);
    }

    /** 書く時の断り(結果の文は原文の結果にも残す。見つからないは見つからないの本文だけ) */
    public function denied(
        AnswerRunContext $step,
        AiToolCall $row,
        AiToolWriteDenial $denial,
        string $text,
    ): ?ConversationItem {
        $item = ToolWriteDenials::item($row->call_id, $denial, $text);

        return $this->finish($step, $row, AiToolCallStatus::Rejected, $denial, $item, $text === '' ? null : $text);
    }

    /** 包みの中の例外と道具の例外(報告して、道具の名前だけの決まった失敗の文) */
    public function exception(AnswerRunContext $step, AiToolCall $row, ToolCallException $exception): ?ConversationItem
    {
        report($exception);
        $item = $this->items->exception($row->call_id, $row->tool_name);

        return $this->finish($step, $row, AiToolCallStatus::ToolError, null, $item);
    }

    private function finish(
        AnswerRunContext $step,
        AiToolCall $row,
        AiToolCallStatus $status,
        ?AiToolWriteDenial $denial,
        ConversationItem $item,
        ?string $text = null,
    ): ?ConversationItem {
        return $this->rows->answered($step, $row, $status, $denial, $text, [], $item) ? $item : null;
    }

    private static function name(AiToolCall $row): string
    {
        return RecordMarkers::neutralize($row->tool_name);
    }
}
