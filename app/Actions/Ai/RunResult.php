<?php

namespace App\Actions\Ai;

/**
 * 実行の結果のうち記録に書くもの(内部。文章と文字起こしで共通の形にし、完了と失敗の書き込みを 1 か所にする =
 * docs の 設計/AI/実行と費用.md 4 節の「2 つの種類に共通の扱い」)。
 */
final readonly class RunResult
{
    /**
     * @param  array<string, mixed>  $usage  使用量・単価・費用(表 ai_runs の列 → 値。分からない列は入れない)
     * @param  string|null  $outputText  応答の原文(ai_run_texts.output_text。分からなければ null = 書かない)
     * @param  string|null  $incompleteReason  不完全のときの細かい理由({@see Api\FailedRun} の定数。ほかは null)
     */
    public function __construct(
        public array $usage,
        public ?string $outputText,
        public ?string $incompleteReason = null,
    ) {}
}
