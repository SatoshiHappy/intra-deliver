<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Workflow\ApplicantApprovalGuard;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 処理の時の指定の入力の検証(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 5.2 の規則と順。文言 = 同時実行と検査.md 13 節)。
 * 有効性は人をまとめて 1 回で引く(指定の数に比例して問い合わせを増やさないため)。承認だけが呼ぶ。鍵を取らない(案件の行の鍵の下で呼ぶ)。
 */
class AssignmentRules
{
    public function __construct(
        private EffectiveUsers $effectiveUsers,
        private ApplicantApprovalGuard $applicantGuard,
    ) {}

    /**
     * @param  int  $designatorId  承認するノード X
     * @param  array<int, list<int>>  $assignments  入力(Y の id → ユーザーの id の並び)
     * @return array<int, list<int>> 検証した指定(Y の id の昇順)
     *
     * @throws ValidationException 規則に外れたとき(assignments・assignments.<Y の id>)
     */
    public function validated(WorkflowCase $case, int $designatorId, array $assignments): array
    {
        $designated = WorkflowCaseNode::query()->with('parent')->where('workflow_case_id', $case->id)->where('designator_workflow_case_node_id', $designatorId)->orderBy('id')->get()->keyBy('id')->all();
        $inputIds = array_keys($assignments);
        sort($inputIds);

        if ($inputIds !== array_keys($designated)) {
            throw ValidationException::withMessages(['assignments' => [CaseOperationErrors::ASSIGNMENTS_MISMATCH]]);
        }

        $messages = $this->messagesOf($case, $designated, $assignments);

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }

        ksort($assignments);

        return $assignments;
    }

    /**
     * Y ごとの最初の誤り(規則 2・3 を全部の Y に当て、通った Y の人をまとめて 1 回で引いてから 4・5)。
     *
     * @param  array<int, WorkflowCaseNode>  $designated  Y の id → Y
     * @param  array<int, list<int>>  $assignments
     * @return array<string, list<string>> assignments.<Y の id> → [文言](Y の id の昇順)
     */
    private function messagesOf(WorkflowCase $case, array $designated, array $assignments): array
    {
        $baseDate = $case->base_date ?? throw new LogicException("未完了の案件 {$case->id} に申請基準日が無い");
        $shapeErrors = array_filter(array_map(fn (WorkflowCaseNode $node) => $this->shapeError($node, $assignments[$node->id]), $designated));
        $checked = array_diff_key($designated, $shapeErrors);
        $people = array_values(array_unique(array_merge([], ...array_values(array_intersect_key($assignments, $checked)))));
        $effectiveIds = $this->effectiveUsers->idsOn($people, $baseDate);
        $applicantIds = $this->applicantGuard->applicantUserIds($case);
        $personErrors = array_filter(array_map(fn (WorkflowCaseNode $node) => $this->personError($node, $assignments[$node->id], $applicantIds, $effectiveIds), $checked));
        $errors = $shapeErrors + $personErrors;
        ksort($errors);
        $messages = [];

        foreach ($errors as $id => $message) {
            $messages["assignments.{$id}"] = [$message];
        }

        return $messages;
    }

    /**
     * 1 つの Y の人数と重複の誤り(規則 2・3 の順で最初のもの。無ければ null)。人の有効性を引く前に見る(大きな入力を問い合わせに渡さない)。
     *
     * @param  list<int>  $userIds
     */
    private function shapeError(WorkflowCaseNode $node, array $userIds): ?string
    {
        $min = $node->min_assignees ?? throw new LogicException("指定されるノード {$node->id} に人数が無い");
        $max = $node->max_assignees ?? throw new LogicException("指定されるノード {$node->id} に人数が無い");
        $count = count($userIds);

        return match (true) {
            $count < $min || $count > $max => $min === $max
                ? sprintf(CaseOperationErrors::ASSIGNMENTS_EXACT_COUNT, $node->displayName(), $min)
                : sprintf(CaseOperationErrors::ASSIGNMENTS_COUNT, $node->displayName(), $min, $max),
            count(array_unique($userIds)) !== $count => CaseOperationErrors::ASSIGNMENTS_DUPLICATE,
            default => null,
        };
    }

    /**
     * 1 つの Y の人の誤り(規則 4・5 の順で最初のもの。無ければ null)。
     *
     * @param  list<int>  $userIds
     * @param  non-empty-list<int>  $applicantIds  申請者として扱う人({@see ApplicantApprovalGuard::applicantUserIds()}。
     *                                             先頭 = 申請者、ほか = 今日の申請者の代理先)
     * @param  list<int>  $effectiveIds  規則 2・3 を通った Y の人のうち申請基準日に有効なもの(まとめて 1 回で引いた)
     */
    private function personError(WorkflowCaseNode $node, array $userIds, array $applicantIds, array $effectiveIds): ?string
    {
        $prevents = $node->prevents_applicant_approval;
        $proxies = array_slice($applicantIds, 1);

        return match (true) {
            array_diff($userIds, $effectiveIds) !== [] => CaseOperationErrors::ASSIGNMENTS_NOT_EFFECTIVE,
            $prevents && in_array($applicantIds[0], $userIds, true) => CaseOperationErrors::ASSIGNMENTS_APPLICANT,
            $prevents && array_intersect($userIds, $proxies) !== [] => CaseOperationErrors::ASSIGNMENTS_APPLICANT_PROXY,
            default => null,
        };
    }
}
