<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowBulkOutcome;

/**
 * 一括承認・一括確認の結果(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節の「応答」。入口が全部の成功ならトースト、
 * ほかは画面の bulkResult に写す = 設計/ワークフロー/案件の運用/画面/README.md)。
 */
final readonly class BulkOperationResult
{
    /** 一括承認 */
    public const APPROVE = 'approve';

    /** 一括確認 */
    public const CONFIRM = 'confirm';

    /**
     * @param  self::APPROVE|self::CONFIRM  $action
     * @param  list<BulkCaseResult>  $rows  送られた並びの順
     */
    public function __construct(
        public string $action,
        public array $rows,
    ) {}

    /**
     * 選んだ案件の数。
     */
    public function total(): int
    {
        return count($this->rows);
    }

    /**
     * 承認・確認できた案件の数。
     */
    public function succeeded(): int
    {
        $done = [WorkflowBulkOutcome::Approved, WorkflowBulkOutcome::Confirmed];

        return count(array_filter($this->rows, fn (BulkCaseResult $row) => in_array($row->outcome, $done, true)));
    }

    /**
     * 画面の bulkResult の値(設計/ワークフロー/案件の運用/画面/README.md の 1.1 節の「一括の結果の値」)。
     *
     * @return array{action: string, total: int, succeeded: int, rows: list<array{
     *     caseId: int, name: string|null, number: string|null, flowName: string|null, result: string,
     *     guidance: string|null,
     * }>}
     */
    public function toScreen(): array
    {
        return [
            'action' => $this->action,
            'total' => $this->total(),
            'succeeded' => $this->succeeded(),
            'rows' => array_map(fn (BulkCaseResult $row) => [
                'caseId' => $row->caseId,
                'name' => $row->name,
                'number' => $row->number,
                'flowName' => $row->flowName,
                'result' => $row->outcome->value,
                'guidance' => $row->guidance,
            ], $this->rows),
        ];
    }
}
