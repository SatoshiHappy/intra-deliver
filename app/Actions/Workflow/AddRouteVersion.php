<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * フローの最後に形つきの版を足す、初期データの業務処理(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 1 節。
 * 鍵 = データモデル/直列化と検査.md の 4.2 節)。画面の版の追加({@see CopyFlowVersion})と分けるのは、過去の版の期間を固定の日付で再現するため。
 */
class AddRouteVersion
{
    public function __construct(private RouteVersionWriter $writer, private FlowVersionStartValidator $startDates) {}

    /**
     * @param  list<RouteNodeInput>  $nodes  位置の順(1 番目 = 申請)
     * @param  list<RouteEdgeInput>  $edges
     * @param  bool  $isDeleted  足した版をユーザー無効で始めるか(初期データの過去の無効の版を再現する)
     * @return int 足した版の id
     *
     * @throws ModelNotFoundException<WorkflowFlow|WorkflowContent> フロー・コンテンツが無いとき
     * @throws ValidationException 開始日(start_date)・ノードと辺の入力の誤り
     * @throws RouteDefinitionViolated 定義の検査に外れたとき
     */
    public function execute(string $flowCode, CarbonImmutable $startDate, array $nodes, array $edges, bool $isDeleted = false): int
    {
        return DB::transaction(function () use ($flowCode, $startDate, $nodes, $edges, $isDeleted) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->lock('for no key update')->firstOrFail();
            $last = WorkflowFlowVersion::query()->where('workflow_flow_id', $flow->id)->orderByDesc('number')->first();

            $start = $this->startDates->validated($startDate, $last);

            $last?->update(['end_date' => $start]);
            $version = WorkflowFlowVersion::query()->create([
                'workflow_flow_id' => $flow->id, 'number' => ($last->number ?? 0) + 1, 'start_date' => $start, 'end_date' => null, 'is_deleted' => $isDeleted,
            ]);
            $moduleId = WorkflowContent::query()->findOrFail($flow->workflow_content_id)->record_module_id;
            $this->writer->write($version->id, $moduleId, $nodes, $edges);

            return $version->id;
        });
    }
}
