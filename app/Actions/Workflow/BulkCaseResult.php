<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowBulkOutcome;

/**
 * 一括承認・一括確認の結果の 1 行(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節の「結果の分け方」と「結果の行の申請の情報」。
 * 画面の bulkResult の rows の行 = 設計/ワークフロー/案件の運用/画面/README.md)。作るのは {@see BulkResultRows} だけ。
 */
final readonly class BulkCaseResult
{
    /**
     * @param  int  $caseId  送られた案件の id
     * @param  string|null  $name  申請名(結果を作る時に閲覧できた案件だけ。ほかは null)
     * @param  string|null  $number  申請番号(同じ)
     * @param  string|null  $flowName  フロー名(同じ)
     * @param  string|null  $guidance  対応の文(無ければ null =「—」)
     */
    public function __construct(
        public int $caseId,
        public ?string $name,
        public ?string $number,
        public ?string $flowName,
        public WorkflowBulkOutcome $outcome,
        public ?string $guidance,
    ) {}
}
