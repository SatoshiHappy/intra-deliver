<?php

namespace Tests\Support;

use App\Actions\Authz\Api\CreateSelectionSubjectGroup;
use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\RecordRevisionWriter;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Records\RecordRevisionAction;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseEdge;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowCaseSendBack;

/**
 * ワークフローの読み取りのテストの案件を作る手助け(案件・ノード・履歴・処理対象者の行はテストで作る = docs の 設計/ワークフロー/認可と一覧.md 7 節)。
 * ノードの動きは docs の 設計/ワークフロー/案件の操作/操作.md 3.3 の「ノードの 3 つの動き」に合わせる(業務処理の順や検証はしない)。
 * 処理対象者・参照者の対象者は認可の口(用途 selection)で作り、人は初期データのユーザー(u0001〜)を使う。
 */
trait BuildsWorkflowCases
{
    /**
     * 初期データのユーザー(渡したコードの順)。
     *
     * @return list<User>
     */
    protected function seededUsers(string ...$codes): array
    {
        return array_map(fn (string $code) => User::query()->where('code', $code)->sole(), array_values($codes));
    }

    /**
     * 条件 1 つの専用の対象者(用途 selection)。
     */
    protected function selection(SelectionConditionKind $kind, int $id): int
    {
        return app(CreateSelectionSubjectGroup::class)->execute([new SelectionCondition($kind, $id)], ['ja' => '対象者']);
    }

    /**
     * 到達していないノードを位置 1 から count まで(位置 1 = 申請、ほかは承認)と、位置の順の一本道の辺・差戻しできる先 = 前の全部(段 2 の形 =
     * docs の 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 14 節の「段 2 の形の一本道のルート」。引戻しの 1 規則は子孫を辺で、差戻しの候補は
     * 差戻しできる先を読む)。
     *
     * @return list<WorkflowCaseNode>
     */
    protected function caseNodes(WorkflowCase $case, int $count): array
    {
        $nodes = array_map(fn (int $position) => WorkflowCaseNode::factory()->create(['workflow_case_id' => $case->id, 'position' => $position]), range(1, $count));

        foreach (array_slice($nodes, 1) as $index => $node) {
            WorkflowCaseEdge::factory()->create(['workflow_case_id' => $case->id, 'from_workflow_case_node_id' => $nodes[$index]->id, 'to_workflow_case_node_id' => $node->id]);

            foreach (array_slice($nodes, 0, $index + 1) as $target) {
                WorkflowCaseSendBack::factory()->create(['workflow_case_id' => $case->id, 'workflow_case_node_id' => $node->id, 'target_workflow_case_node_id' => $target->id]);
            }
        }

        return $nodes;
    }

    /**
     * 案件の今のノード(位置の順)。
     *
     * @return list<WorkflowCaseNode>
     */
    protected function nodesOf(WorkflowCase $case): array
    {
        return array_values(WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->orderBy('position')->get()->all());
    }

    /**
     * 履歴の行を入れ、引戻し以外はそのノードをその行で処理済みにする(そのノードの処理対象者の行は消す)。
     */
    protected function process(WorkflowCaseNode $node, User $actor, WorkflowAction $action, ?WorkflowCaseNode $sendBackTo = null): WorkflowCaseHistory
    {
        $history = WorkflowCaseHistory::factory()->create([
            'workflow_case_node_id' => $node->id,
            'user_id' => $actor->id,
            'action' => $action,
            'send_back_workflow_case_node_id' => $sendBackTo?->id,
        ]);

        if ($action !== WorkflowAction::PullBack) {
            WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $node->id)->delete();
            $node->update(['state' => WorkflowNodeState::Done, 'workflow_case_history_id' => $history->id]);
        }

        return $history;
    }

    /**
     * 申請・再申請の行が、レコードの値と参照(項目の id → 相手のレコード)を書き換えた後の版を指すようにする
     * (版は行の操作の契機で書く = docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。
     *
     * @param  array<int, mixed>  $fieldValues
     * @param  array<int, Record>  $references
     */
    protected function pointAtSubmittedRevision(
        WorkflowCaseHistory $history,
        Record $record,
        array $fieldValues,
        array $references,
    ): void {
        $record->update(['field_values' => $fieldValues]);

        foreach ($references as $fieldId => $target) {
            $columns = [
                'record_module_id' => $record->record_module_id,
                'record_id' => $record->id,
                'record_field_id' => $fieldId,
            ];
            RecordReference::query()->where($columns)->delete();
            RecordReference::factory()->create([
                ...$columns,
                'user_id' => null,
                'referenced_record_module_id' => $target->record_module_id,
                'referenced_record_id' => $target->id,
            ]);
        }

        $operation = $history->action === WorkflowAction::Reapply ? '再申請' : '申請';
        $origin = new RecordRevisionOrigin($operation, '申請');
        $revisionId = app(RecordRevisionWriter::class)->write($record->id, RecordRevisionAction::Update, null, $origin);
        $factoryRevisionId = $history->record_revision_id;
        $history->update(['record_revision_id' => $revisionId]);
        // ファクトリが作った中身の空の版は指されなくなるので消す(版の番号の欠けは許す)
        RecordRevision::query()->whereKey($factoryRevisionId)->delete();
    }

    /**
     * ノードを到達させる(申請のノードは再申請待ち、承認のノードは承認待ち。到達日時は今)。
     */
    protected function reach(WorkflowCaseNode $node, User ...$targets): void
    {
        $node->update(['state' => $node->kind === WorkflowNodeKind::Apply ? WorkflowNodeState::Reapply : WorkflowNodeState::Waiting, 'workflow_case_history_id' => null, 'reached_at' => now()]);

        foreach ($targets as $target) {
            $this->target($node, $target);
        }
    }

    /**
     * ノードを戻す(到達していない)。
     */
    protected function unreach(WorkflowCaseNode $node): void
    {
        WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $node->id)->delete();
        $node->update(['state' => WorkflowNodeState::Unreached, 'workflow_case_history_id' => null]);
    }

    /**
     * 処理対象者の行だけを入れる(現在のノードでないノードに残った行を作るときにも使う)。
     */
    protected function target(WorkflowCaseNode $node, User $user): void
    {
        WorkflowCaseNodeTarget::factory()->create(['workflow_case_node_id' => $node->id, 'user_id' => $user->id]);
    }
}
