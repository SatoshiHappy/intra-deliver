<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\SendBackCase;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 分岐のあるルートの再申請(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 8 節の再申請の行「分岐を新しい値で評価し直す」「辿り着く分岐開始のどれでも
 * 真の辺がある」、7 節の (b)「業務データの入れ物の口を展開の前に呼び、計画が拒めばレコードの更新も同じトランザクションで巻き戻る」、13 節の文言)。R1 で、部長
 * (予算 ≥ 300 の枝)まで進めてから申請へ差戻した案件を使う。
 */
final class CaseReapplyOnRoutesTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use OperatesOnRoutes;

    /**
     * 予算を 500 → 100 に変えて再申請すると、分岐開始を再び評価し、部長の枝を省いて合議へ進む(taken も新しい評価の結果)。
     */
    public function test_a_reapplication_with_changed_values_takes_the_other_branch(): void
    {
        $case = $this->sentBackFromTheManager();

        $result = app(ReapplyCase::class)->execute($this->contextOf($this->seededUser('u0001')), $case->id, $this->revisionOf($case), $this->projectInput(['budget' => 100]));
        $this->assertRouteInvariants($case);
        $this->assertResult($case, $result, WorkflowAction::Reapply, 'u0001', ['section_chief' => ['u0002']], null);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);

        $this->assertSame(['skipped', 'passed', 'running'], [$this->shape($case)['manager'], $this->shape($case)['budget_end'], $this->shape($case)['council']]);
        $this->assertSame([false, true], $this->taken($case));
    }

    /**
     * どの辺の条件も偽になる値(予算が空)の再申請は 422 case。業務データの入れ物の更新も巻き戻り、レコードの値・件数・案件の状態・改訂番号・ノードは再申請の前のまま。
     * 初期データの申請書は予算が必須で、空は必須の誤りが先に返る(docs の 設計/ワークフロー/ルートの深さ/初期データ.md の 3.2 節)。
     * この検査のためにだけ、テストの中で予算を必須でなくする。
     */
    public function test_a_reapplication_where_no_branch_condition_holds_is_rejected_and_the_record_update_is_rolled_back(): void
    {
        $case = $this->sentBackFromTheManager();
        RecordField::query()->where('code', 'budget')
            ->whereRelation('recordModule', 'code', $this->projectFormModuleCode())
            ->update(['is_required' => false]);
        $record = Record::query()->findOrFail($case->record_id);
        $before = [$record->field_values, $this->workflowCounts(), $case->refresh()->state, $this->revisionOf($case), $this->shape($case), $this->taken($case)];

        $errors = $this->validationErrors(fn () => app(ReapplyCase::class)->execute($this->contextOf($this->seededUser('u0001')), $case->id, $this->revisionOf($case), $this->projectInput(['budget' => null])));

        $this->assertSame(['case' => '分岐「予算」の条件に該当するルートがないため、申請できません'], $errors);
        $this->assertSame($before, [$record->refresh()->field_values, $this->workflowCounts(), $case->refresh()->state, $this->revisionOf($case), $this->shape($case), $this->taken($case)]);
        $this->assertSame(WorkflowCaseState::Active, $case->state);
    }

    /**
     * R1(予算 500)で課長が指定して承認し、部長が申請へ差戻した案件(分岐開始と taken も戻っている)。
     */
    private function sentBackFromTheManager(): WorkflowCase
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 500]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0010']]);
        $this->assertSame([true, false], $this->taken($case));
        $ids = $this->labelIds($case);
        app(SendBackCase::class)->execute($this->contextOf($this->seededUser('u0003')), $case->id, $this->revisionOf($case), $ids['manager'], $ids['apply'], null);
        $this->assertRouteInvariants($case);
        $this->assertSame(['reapply[u0001]', 'unreached', 'unreached'], [$this->shape($case)['apply'], $this->shape($case)['budget'], $this->shape($case)['manager']]);
        $this->assertSame([null, null], $this->taken($case));

        return $case;
    }

    /**
     * @return list<bool|null> 分岐開始「予算」から出る辺の taken(並びの順)
     */
    private function taken(WorkflowCase $case): array
    {
        return array_values(WorkflowCaseEdge::query()->where('workflow_case_id', $case->id)->where('from_workflow_case_node_id', $this->labelIds($case)['budget'])->orderBy('sort_key')->get()->map(fn (WorkflowCaseEdge $edge) => $edge->taken)->all());
    }
}
