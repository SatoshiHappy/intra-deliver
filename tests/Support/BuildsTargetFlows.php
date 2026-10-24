<?php

namespace Tests\Support;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\CreateWorkflowContent;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\User;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowFlowVersion;
use App\Models\Workflow\WorkflowRouteNode;

/**
 * 申請対象からの申請のテストのフロー(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.1)。
 * 申請書の業務モジュールはテストで作り、コンテンツは本物の業務処理で作る(初期データのコンテンツはまだ申請対象を持たない)。
 */
trait BuildsTargetFlows
{
    use BuildsRecordModules;

    /**
     * フロー(コンテンツ = 申請書の業務モジュール m_<コード>(作るときに全員へ $formPermitted を許す)。$target があれば、その業務モジュールを指す
     * 他モジュール参照の項目 target を申請対象にする)と、版 1 つ(factory の既定の期間)のルート = 申請(渡した人が対象者)+ 承認。
     *
     * @param  list<RecordModuleAction>  $formPermitted
     * @param  list<User>  $applicants
     */
    protected function targetFlow(
        string $code,
        ?RecordModule $target,
        array $formPermitted,
        array $applicants,
        int $sortKey = 0,
    ): WorkflowFlow {
        $form = $this->recordModule("m_{$code}", $formPermitted);
        $targetField = $target === null ? null : $this->referenceField($form, 'target', $target)->code;
        $content = app(CreateWorkflowContent::class)
            ->execute($form->code, "content_{$code}", "{$code} の申請書", $targetField);
        $flow = WorkflowFlow::factory()->create([
            'code' => $code,
            'name' => "{$code} の名前",
            'workflow_content_id' => $content,
            'sort_key' => $sortKey,
        ]);
        $version = WorkflowFlowVersion::factory()->create(['workflow_flow_id' => $flow->id]);
        $conditions = array_map(
            fn (User $user) => new SelectionCondition(SelectionConditionKind::User, $user->id),
            $applicants,
        );
        WorkflowRouteNode::factory()->create([
            'workflow_flow_version_id' => $version->id,
            'position' => 1,
            'authz_subject_group_id' => app(CreateSelectionSubjectGroup::class)->execute($conditions, ['ja' => '申請']),
        ]);
        WorkflowRouteNode::factory()->create(['workflow_flow_version_id' => $version->id, 'position' => 2]);

        return $flow;
    }
}
