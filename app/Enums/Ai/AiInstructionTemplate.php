<?php

namespace App\Enums\Ai;

/**
 * 指示の組み立ての口が返す定型の文の種類(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5 の定型の表のうち文を持つ行。2 つの版で同じ一覧 =
 * 設計/AI/設定とプロンプト/README.md 11.4)。保存の分け・持ち主・呼び手が渡す値の鍵は種類が持ち、版と起動の時の確かめはここから引く。
 */
enum AiInstructionTemplate: string
{
    case ToolUseRejected = 'tool_use_rejected';

    case ToolUseRejectedWithReason = 'tool_use_rejected_with_reason';

    case UserCancelled = 'user_cancelled';

    case StoppedWithoutResult = 'stopped_without_result';

    case Interrupted = 'interrupted';

    case InterruptedForToolUse = 'interrupted_for_tool_use';

    case StoppedByTimeLimit = 'stopped_by_time_limit';

    case RuleDenied = 'rule_denied';

    case SourceFileNotFound = 'source_file_not_found';

    case ToolInputInvalid = 'tool_input_invalid';

    case UnknownTool = 'unknown_tool';

    case ToolError = 'tool_error';

    case ToolException = 'tool_exception';

    case EmptyResult = 'empty_result';

    case LargeResultSearch = 'large_result_search';

    case LargeResultRead = 'large_result_read';

    case LargeResultAggregate = 'large_result_aggregate';

    case LargeResultTruncated = 'large_result_truncated';

    case CreatedFilesAfterCompaction = 'created_files_after_compaction';

    case CompactionToolDenied = 'compaction_tool_denied';

    case ToolResultMissing = 'tool_result_missing';

    case OrphanResultsRemoved = 'orphan_results_removed';

    case OldTurnsDropped = 'old_turns_dropped';

    case OutputContinuation = 'output_continuation';

    case ResumeAfterCut = 'resume_after_cut';

    case NoResponse = 'no_response';

    case UnknownOutcomeNotice = 'unknown_outcome_notice';

    /** 送るときだけの定型 */
    private const SENT_ONLY = [
        self::CompactionToolDenied,
        self::ToolResultMissing,
        self::OrphanResultsRemoved,
        self::OldTurnsDropped,
        self::OutputContinuation,
        self::ResumeAfterCut,
        self::NoResponse,
        self::UnknownOutcomeNotice,
    ];

    /** 持ち主が ③ の定型(公開の側に 1 つだけ置き、2 つの版が同じ文を返す = docs の 設計/AI/設定とプロンプト/README.md 11.4)。分け方の正本 */
    private const SHARED = [
        self::StoppedByTimeLimit,
        self::SourceFileNotFound,
        self::ToolException,
        self::LargeResultSearch,
        self::LargeResultRead,
        self::LargeResultAggregate,
        self::LargeResultTruncated,
        self::CreatedFilesAfterCompaction,
        self::UnknownOutcomeNotice,
    ];

    /** 会話の保存に書く文か(偽 = 送るときだけ) */
    public function isStored(): bool
    {
        return ! in_array($this, self::SENT_ONLY, true);
    }

    /** 持ち主が ③ の定型か */
    public function isShared(): bool
    {
        return in_array($this, self::SHARED, true);
    }

    /**
     * 呼び手がいつも渡す値の鍵(引数の誤りの問題の文は並びで渡す)。起動の時の確かめは、文の置き換え口がこの中にあるかを見る。
     *
     * @return list<string>
     */
    public function valueKeys(): array
    {
        return match ($this) {
            self::ToolUseRejectedWithReason => ['user_feedback'],
            self::RuleDenied => ['tool_name', 'denial_detail'],
            self::ToolInputInvalid => ['tool_name', 'problems'],
            self::UnknownTool, self::ToolException, self::EmptyResult => ['tool_name'],
            self::ToolError => ['error'],
            self::LargeResultSearch, self::LargeResultRead, self::LargeResultAggregate, self::LargeResultTruncated => [
                'original_length',
                'preview_length',
                'preview',
            ],
            self::CreatedFilesAfterCompaction => ['created_files'],
            self::UnknownOutcomeNotice => ['unknown_calls'],
            default => [],
        };
    }

    /**
     * 持ち主が版の種類(持ち主が ③ でない物)。
     *
     * @return list<self>
     */
    public static function versionCases(): array
    {
        $own = array_filter(self::cases(), fn (self $template): bool => ! in_array($template, self::SHARED, true));

        return array_values($own);
    }

    /**
     * 持ち主が ③ の種類。
     *
     * @return list<self>
     */
    public static function sharedCases(): array
    {
        return self::SHARED;
    }
}
