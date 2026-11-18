<?php

namespace App\Enums\Ai;

/**
 * 指示の組み立ての口が返す定型の文の種類(docs の 設計/AI/設定とプロンプト/使う文と組み立て.md 11.5 の定型の表のうち文を持つ行と、送る履歴に足す文の
 * 日付の変化と、移した版が送る前の整えで置く置き換えの文。2 つの版で同じ一覧 = 設計/AI/設定とプロンプト/README.md 11.4)。保存の分け・持ち主・呼び手が渡す値の鍵は種類が持ち、版と起動の時の確かめはここから引く。
 */
enum AiInstructionTemplate: string
{
    /** 道具の結果の本文から入る置き換え口(値に保存せず、本文の欄から入れる) */
    public const BODY_VALUE_KEY = 'preview';

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

    case DateChange = 'date_change';

    case NoContent = 'no_content';

    case NoMessageContent = 'no_message_content';

    case ToolUseInterrupted = 'tool_use_interrupted';

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
        return $this->itemType() !== null;
    }

    /**
     * その定型の文を保存する項目の種類(無し = 送るときだけ。定義 = docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の項目の種類)。
     */
    public function itemType(): ?ConversationItemType
    {
        return match ($this) {
            self::ToolUseRejected,
            self::ToolUseRejectedWithReason,
            self::UserCancelled,
            self::StoppedWithoutResult,
            self::StoppedByTimeLimit,
            self::RuleDenied,
            self::SourceFileNotFound,
            self::ToolInputInvalid,
            self::UnknownTool,
            self::ToolError,
            self::ToolException,
            self::EmptyResult,
            self::LargeResultSearch,
            self::LargeResultRead,
            self::LargeResultAggregate,
            self::LargeResultTruncated => ConversationItemType::ToolResult,
            self::DateChange, self::UnknownOutcomeNotice => ConversationItemType::AddedText,
            self::Interrupted, self::InterruptedForToolUse => ConversationItemType::Interruption,
            self::CreatedFilesAfterCompaction => ConversationItemType::CompactSummary,
            self::CompactionToolDenied,
            self::ToolResultMissing,
            self::OrphanResultsRemoved,
            self::OldTurnsDropped,
            self::OutputContinuation,
            self::ResumeAfterCut,
            self::NoResponse,
            self::NoContent,
            self::NoMessageContent,
            self::ToolUseInterrupted => null,
        };
    }

    /** 道具の結果の本文を取る定型か(値の鍵が本文の口を含む) */
    public function takesBody(): bool
    {
        return in_array(self::BODY_VALUE_KEY, $this->valueKeys(), true);
    }

    /**
     * 項目に保存する値の鍵(呼び手が渡す鍵から、本文から入る口を除いた物)。足す文と道具の結果の保存の形の一部なので、変えるのは
     * docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の形の版の規則の変更に当たる。
     *
     * @return list<string>
     */
    public function storedValueKeys(): array
    {
        return array_values(array_diff($this->valueKeys(), [self::BODY_VALUE_KEY]));
    }

    /** 足す文の項目が、知らせた呼び出しの id を持つ定型か(結果の分からない書き込みの知らせ = 設計/AI/エージェントの実行/途中の表示と上限.md 9.8) */
    public function carriesCallIds(): bool
    {
        return $this === self::UnknownOutcomeNotice;
    }

    /**
     * 値のうち日の形(Y-m-d)を取る鍵。
     *
     * @return list<string>
     */
    public function dateValueKeys(): array
    {
        return $this === self::DateChange ? ['new_date'] : [];
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
            self::DateChange => ['new_date'],
            default => [],
        };
    }

    /**
     * 値が文字列の並びの鍵(ほかの鍵の値は文字列)。鍵ごとの値の形は 2 つの版で同じ(docs の 設計/AI/設定とプロンプト/README.md 11.4)。
     *
     * @return list<string>
     */
    public function listValueKeys(): array
    {
        return match ($this) {
            self::ToolInputInvalid => ['problems'],
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
