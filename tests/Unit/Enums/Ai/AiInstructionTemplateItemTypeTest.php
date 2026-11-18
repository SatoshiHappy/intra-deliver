<?php

namespace Tests\Unit\Enums\Ai;

use App\Enums\Ai\AiInstructionTemplate as T;
use App\Enums\Ai\ConversationItemType as Item;
use PHPUnit\Framework\TestCase;

/**
 * 定型ごとの、文を保存する項目の種類(保存するかはこの値から引く。docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の項目の種類)。表の全行を通す。
 */
final class AiInstructionTemplateItemTypeTest extends TestCase
{
    /** 定型 → 文を保存する項目の種類(無し = 送るときだけ) */
    private const EXPECTED = [
        'tool_use_rejected' => Item::ToolResult,
        'tool_use_rejected_with_reason' => Item::ToolResult,
        'user_cancelled' => Item::ToolResult,
        'stopped_without_result' => Item::ToolResult,
        'stopped_by_time_limit' => Item::ToolResult,
        'rule_denied' => Item::ToolResult,
        'source_file_not_found' => Item::ToolResult,
        'tool_input_invalid' => Item::ToolResult,
        'unknown_tool' => Item::ToolResult,
        'tool_error' => Item::ToolResult,
        'tool_exception' => Item::ToolResult,
        'empty_result' => Item::ToolResult,
        'large_result_search' => Item::ToolResult,
        'large_result_read' => Item::ToolResult,
        'large_result_aggregate' => Item::ToolResult,
        'large_result_truncated' => Item::ToolResult,
        'date_change' => Item::AddedText,
        'unknown_outcome_notice' => Item::AddedText,
        'interrupted' => Item::Interruption,
        'interrupted_for_tool_use' => Item::Interruption,
        'created_files_after_compaction' => Item::CompactSummary,
        'compaction_tool_denied' => null,
        'tool_result_missing' => null,
        'orphan_results_removed' => null,
        'old_turns_dropped' => null,
        'output_continuation' => null,
        'resume_after_cut' => null,
        'no_response' => null,
        'no_content' => null,
        'no_message_content' => null,
        'tool_use_interrupted' => null,
    ];

    public function test_every_template_has_its_item_type(): void
    {
        $cases = array_map(static fn (T $template): string => $template->value, T::cases());
        $this->assertEqualsCanonicalizing(array_keys(self::EXPECTED), $cases);

        foreach (self::EXPECTED as $value => $type) {
            $template = T::from($value);
            $this->assertSame($type, $template->itemType(), $value);
        }
    }
}
