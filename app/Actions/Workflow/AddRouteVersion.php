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
 * フローの最後に形つきの版(ノード・辺・差戻しできる先つき)を足す(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 1 節。呼び手は初期データだけ)。
 * 画面の版の追加(4 節。開始日の範囲 = 今日から 1 年以内・最後の版の複製)とは別の業務処理で、開始日は最後の版の開始日より後だけを確かめる
 * (過去の版の期間を固定の日付で再現するため)。同じトランザクションで最後の版の終了日を新しい開始日にする(空白を作らない)。
 * 鍵 = フローの行 FOR NO KEY UPDATE(データモデル/直列化と検査.md 4.2)。ルートは {@see RouteVersionWriter} が書き、定義の検査を通す。
 */
class AddRouteVersion
{
    public function __construct(private RouteVersionWriter $writer) {}

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

            if ($last !== null && $startDate->lessThanOrEqualTo($last->start_date)) {
                throw ValidationException::withMessages(['start_date' => ["開始日は版 {$last->number} の開始日 {$last->start_date->toDateString()} より後の日付を入力してください"]]);
            }

            $last?->update(['end_date' => $startDate->toDateString()]);
            $version = WorkflowFlowVersion::query()->create([
                'workflow_flow_id' => $flow->id, 'number' => ($last->number ?? 0) + 1, 'start_date' => $startDate->toDateString(), 'end_date' => null, 'is_deleted' => $isDeleted,
            ]);
            $moduleId = WorkflowContent::query()->findOrFail($flow->workflow_content_id)->record_module_id;
            $this->writer->write($version->id, $moduleId, $nodes, $edges);

            return $version->id;
        });
    }
}
