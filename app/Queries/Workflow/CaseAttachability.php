<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowContent;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Records\Api\RecordModuleSettings;
use LogicException;

/**
 * 添付の可否の判定の 1 か所(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 1.1 節、操作ごとの行 = 2 節の表)。
 * 業務処理の検証・画面の props が同じものを呼ぶ。判定を求める者は見ない(操作の可否は呼び手が済ませた後に呼ぶ)。鍵を取らない。
 */
class CaseAttachability
{
    public function __construct(private RecordModuleSettings $modules) {}

    /**
     * 業務モジュールの「添付ファイルを使用」・フローの「添付を使う」・ステップが許す、の 3 つがすべて真か。
     *
     * @param  WorkflowCase|null  $case  申請の後の操作の案件(写した値を読む)。申請の前の操作(一時保存・申請)は渡さない
     * @param  WorkflowCaseNode|null  $node  操作するノード(承認の系統と引戻しだけ。引戻しは引戻す先のノード)
     * @param  CaseSituation|null  $situation  処理者で読んだ状況(保留解除だけ。処理者が保留者側かを答える)
     *
     * @throws LogicException 案件・ノード・状況の渡し方が操作に合わないとき(呼び手の誤り)
     */
    public function canAttach(
        WorkflowOperation $operation,
        WorkflowFlow $flow,
        ?WorkflowCase $case = null,
        ?WorkflowCaseNode $node = null,
        ?CaseSituation $situation = null,
    ): bool {
        return $this->stepAllows($operation, $node, $situation)
            && $this->flowAllows($operation, $flow, $case)
            && $this->modules->isAttachmentEnabled($this->recordModuleIdOf($flow));
    }

    /**
     * 申請の系統は真(申請のノードは設定を持たない = 1.3 節)、承認の系統と引戻しは操作するノードの写した設定。
     * 引戻す先が申請のノードなら、その設定はいつも偽(DB の検査制約)なので申請の系統と同じ真になる。
     * 保留解除は、加えて処理者が保留者側のときだけ(案件の管理者として解除する形は受けない = 2 節の「保留解除」)。
     */
    private function stepAllows(WorkflowOperation $operation, ?WorkflowCaseNode $node, ?CaseSituation $situation): bool
    {
        return match ($operation) {
            WorkflowOperation::SaveDraft, WorkflowOperation::OverwriteDraft, WorkflowOperation::Apply,
            WorkflowOperation::Reapply, WorkflowOperation::Withdraw => true,
            WorkflowOperation::DeleteDraft, WorkflowOperation::Confirm => false,
            WorkflowOperation::Approve, WorkflowOperation::ApproveEarly, WorkflowOperation::Reject,
            WorkflowOperation::SendBack, WorkflowOperation::PullBack, WorkflowOperation::Hold,
            WorkflowOperation::Transfer => $this->nodeAllows($operation, $node),
            WorkflowOperation::Release => $this->nodeAllows($operation, $node)
                && $this->isHoldSide($node, $situation),
        };
    }

    /**
     * @throws LogicException 操作するノードが無いとき
     */
    private function nodeAllows(WorkflowOperation $operation, ?WorkflowCaseNode $node): bool
    {
        return ! ($node ?? throw new LogicException("操作するノードが無い: {$operation->value}"))->prevents_attachment_add;
    }

    /**
     * @throws LogicException 状況が無いとき
     */
    private function isHoldSide(?WorkflowCaseNode $node, ?CaseSituation $situation): bool
    {
        if ($situation === null) {
            throw new LogicException('保留解除の添付の可否に状況が無い');
        }

        return $node !== null && ProcessorRoles::isHoldSideAt($situation, $node->id);
    }

    /**
     * 申請の前はフローの今の値、申請の後は案件に写した値(申請した時点の規則で最後まで進む = 1.1 節の「どの値を読むか」)。
     */
    private function flowAllows(WorkflowOperation $operation, WorkflowFlow $flow, ?WorkflowCase $case): bool
    {
        $beforeApplication = in_array(
            $operation,
            [WorkflowOperation::SaveDraft, WorkflowOperation::OverwriteDraft, WorkflowOperation::Apply],
            true,
        );

        if ($beforeApplication !== ($case === null)) {
            throw new LogicException("案件の渡し方が操作に合わない: {$operation->value}");
        }

        return $case === null
            ? $flow->is_attachment_enabled
            : $case->is_attachment_enabled ?? throw new LogicException("案件 {$case->id} に写した値が無い");
    }

    /**
     * コンテンツの業務モジュール(外部キーで必ずある)。
     */
    private function recordModuleIdOf(WorkflowFlow $flow): int
    {
        return WorkflowContent::query()->findOrFail($flow->workflow_content_id)->record_module_id;
    }
}
