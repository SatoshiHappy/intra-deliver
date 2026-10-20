<?php

namespace Database\Factories\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * 引戻しの行は、同じ案件の最新の行(無ければ作る)を引戻した行にし、node_changes を持たない。ほかの行は変えたノードの無い node_changes(空の配列)を持つ。
 * 申請・再申請の行は、案件のレコードの次の番号の更新履歴の版を作って指す(レコードの無い案件は、版と一緒に作るレコードの版)。
 *
 * @extends Factory<WorkflowCaseHistory>
 */
class WorkflowCaseHistoryFactory extends Factory
{
    /**
     * Define the model's default state(ノードの種類に合う処理の行。申請のノードなら申請(版を指す)、承認のノードなら承認).
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workflow_case_node_id' => WorkflowCaseNode::factory(),
            'workflow_case_id' => fn (array $attributes): int => WorkflowCaseNode::query()->whereKey($attributes['workflow_case_node_id'])->sole()->workflow_case_id,
            'action' => fn (array $attributes): WorkflowAction => WorkflowCaseNode::query()->whereKey($attributes['workflow_case_node_id'])->sole()->kind === WorkflowNodeKind::Apply
                ? WorkflowAction::Apply
                : WorkflowAction::Approve,
            'user_id' => User::factory(),
            'comment' => null,
            'send_back_workflow_case_node_id' => null,
            'record_revision_id' => fn (array $attributes): ?int => $this->revisionOf($attributes),
            'node_changes' => fn (array $attributes): ?array => $attributes['action'] === WorkflowAction::PullBack ? null : [],
            'reverted_workflow_case_history_id' => fn (array $attributes): ?int => $attributes['action'] === WorkflowAction::PullBack
                ? $this->latestOrNew($attributes)
                : null,
            'executor_user_id' => null,
        ];
    }

    /**
     * 代理で処理した行にする(実行者 = 新しいユーザー)。
     */
    public function byProxy(): static
    {
        return $this->state(fn (): array => ['executor_user_id' => User::factory()]);
    }

    /**
     * 差戻しの行にする(位置 2 の承認のノードから、同じ案件の位置 1 の申請のノードへ)。
     */
    public function sendBack(): static
    {
        return $this->state(fn (): array => [
            'workflow_case_node_id' => WorkflowCaseNode::factory()->state(['position' => 2]),
            'action' => WorkflowAction::SendBack,
            'send_back_workflow_case_node_id' => fn (array $attributes) => WorkflowCaseNode::factory()->state(['workflow_case_id' => $attributes['workflow_case_id'], 'position' => 1]),
        ]);
    }

    /**
     * 引戻しの行にする(引戻した行 = 同じ案件の最新の行。無ければ同じノードの行を作る)。
     */
    public function pullBack(): static
    {
        return $this->state(fn (): array => ['action' => WorkflowAction::PullBack]);
    }

    /**
     * 申請・再申請の行が指す版(契機の語は行の操作。ほかの行は null)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function revisionOf(array $attributes): ?int
    {
        $operation = match ($attributes['action']) {
            WorkflowAction::Apply => '申請',
            WorkflowAction::Reapply => '再申請',
            default => null,
        };

        return $operation === null ? null : $this->revisionFor($attributes['workflow_case_id'], $operation);
    }

    /**
     * 案件のレコードの次の番号の更新の版。
     *
     * @throws LogicException レコードの無い案件(一時保存)のとき(申請・再申請の行の案件は active() で作る)
     */
    private function revisionFor(int $caseId, string $operation): int
    {
        $recordId = WorkflowCase::query()->findOrFail($caseId)->record_id
            ?? throw new LogicException("一時保存の案件 {$caseId} に申請・再申請の行は作れない(案件を active() で作る)");
        $record = Record::query()->findOrFail($recordId);
        $latest = (int) RecordRevision::query()->where('record_id', $record->id)->max('revision');

        return RecordRevision::factory()->update()->fromOperation($operation, '申請')->createOne([
            'record_module_id' => $record->record_module_id,
            'record_id' => $record->id,
            'revision' => $latest + 1,
        ])->id;
    }

    /**
     * 同じ案件の最新の履歴の行(無ければ、同じノードの行を作る)。
     *
     * @param  array<string, mixed>  $attributes
     */
    private function latestOrNew(array $attributes): int
    {
        $latest = WorkflowCaseHistory::query()->where('workflow_case_id', $attributes['workflow_case_id'])->max('id');

        return $latest === null
            ? WorkflowCaseHistory::factory()->createOne(['workflow_case_node_id' => $attributes['workflow_case_node_id']])->id
            : (int) $latest;
    }
}
