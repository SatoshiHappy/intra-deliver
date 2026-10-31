<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 画面の版の追加(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 4 節。鍵 = データモデル/直列化と検査.md の 4.2 節)。
 */
class CopyFlowVersion
{
    public function __construct(private RouteVersionWriter $writer, private FlowVersionStartValidator $startDates) {}

    /**
     * @return int 足した版の番号
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     * @throws ValidationException 開始日(start_date)の誤り
     * @throws RouteDefinitionViolated 複製が定義の検査に外れたとき
     * @throws LogicException フローに版が無いとき
     */
    public function execute(string $flowCode, CarbonImmutable $startDate): int
    {
        return DB::transaction(function () use ($flowCode, $startDate) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->lock('for no key update')->firstOrFail();
            $last = WorkflowFlowVersion::query()->where('workflow_flow_id', $flow->id)->orderByDesc('number')->first()
                ?? throw new LogicException("フローに版がありません: {$flowCode}");
            $start = $this->startDates->validated($startDate, $last);
            $this->throwIfOutOfYear($start);

            $last->update(['end_date' => $start]);
            $version = WorkflowFlowVersion::query()->create([
                'workflow_flow_id' => $flow->id, 'number' => $last->number + 1,
                'start_date' => $start, 'end_date' => null, 'is_deleted' => false,
            ]);
            $this->writer->copy($version->id, $last->id);

            return $version->number;
        });
    }

    /**
     * @param  string  $start  開始日(Y-m-d)
     *
     * @throws ValidationException
     */
    private function throwIfOutOfYear(string $start): void
    {
        $today = CarbonImmutable::today(AccessContext::TIMEZONE);

        if ($start < $today->toDateString() || $start > $today->addYearNoOverflow()->toDateString()) {
            throw ValidationException::withMessages(['start_date' => [FlowDefinitionErrors::START_DATE_WITHIN_YEAR]]);
        }
    }
}
