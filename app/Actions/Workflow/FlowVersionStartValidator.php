<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowFlowVersion;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * 版の開始日を最後の版の開始日と比べる 1 か所(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 1 節)。版を足す 2 つの業務処理が同じ比べ方をするため。
 * 日付だけで比べるのは、渡された開始日の時刻とモデルの日付の cast の時間帯で結果を変えないため。
 */
class FlowVersionStartValidator
{
    /**
     * @param  WorkflowFlowVersion|null  $last  フローの最後の版(null = 版の無いフロー)
     * @return string 開始日(Y-m-d)
     *
     * @throws ValidationException start_date
     */
    public function validated(CarbonImmutable $startDate, ?WorkflowFlowVersion $last): string
    {
        $start = $startDate->toDateString();
        $lastStart = $last?->start_date->toDateString();

        if ($last !== null && $start <= $lastStart) {
            $message = sprintf(FlowDefinitionErrors::START_DATE_AFTER_LAST, $last->number, $lastStart);

            throw ValidationException::withMessages(['start_date' => [$message]]);
        }

        return $start;
    }
}
