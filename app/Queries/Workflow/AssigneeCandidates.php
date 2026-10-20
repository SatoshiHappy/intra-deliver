<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\Api\MasterCandidates;
use App\Support\AccessContext;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 処理対象者の候補(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 5.2 の「候補の検索」、画面.md 1.1)。
 * 操作の役割の判定は 1 か所の {@see CaseOperationDecider::permitsIn()} を通す(判定する役割が増えても、この口を変えないため)。
 * 入口は処理者を受けないので、P の候補を 4.3 の画面の選択肢と同じ口で作る(設計/ワークフロー/案件の運用/案件の操作/操作.md の 8 節)。
 * 申請者として扱う人({@see ApplicantApprovalGuard::applicantUserIds()})は、その数だけ多く引いて除き上限に切る(共通マスタの口
 * {@see MasterCandidates::usersOn()} を変えないため)。鍵を取らない。
 */
class AssigneeCandidates
{
    /** 候補の上限(画面.md 1.1) */
    public const LIMIT = 20;

    public function __construct(
        private CaseVisibility $visibility,
        private CaseOperationDecider $decider,
        private MasterCandidates $candidates,
        private ApplicantApprovalGuard $applicantGuard,
        private WorkflowProxies $proxies,
    ) {}

    /**
     * 候補を引いてよいかを確かめる。
     *
     * @param  mixed  $nodeId  入力の node(整数として読めなければ Y が無いとして 422 case)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できない・一時保存のとき
     * @throws ValidationException Y がこの案件の指定されるノードでないとき(case)
     * @throws AuthorizationException 今の利用者が X を承認できないとき
     */
    public function authorize(AccessContext $context, int $caseId, mixed $nodeId): void
    {
        $requester = Requester::fromAccessContext($context);
        $case = $this->visibility->visible(WorkflowCase::query()->whereKey($caseId)->where('state', '<>', WorkflowCaseState::Draft), $requester, $context->date)->firstOrFail();
        $designated = is_numeric($nodeId) && preg_match('/^[1-9][0-9]{0,17}$/D', (string) $nodeId) === 1
            ? WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->whereKey((int) $nodeId)->whereNotNull('designator_workflow_case_node_id')->first()
            : null;

        if ($designated === null) {
            throw ValidationException::withMessages(['case' => [CaseOperationTable::NOT_PERMITTED_MESSAGE]]);
        }

        $designatorId = $designated->designator_workflow_case_node_id
            ?? throw new LogicException("指定されるノード {$designated->id} に指定するノードが無い");

        if (! $this->permitsSomeProcessor($requester, $context->date, $case, $designatorId)) {
            throw new AuthorizationException(CaseOperationTable::NOT_PERMITTED_MESSAGE);
        }
    }

    /**
     * 処理者 P の候補({@see WorkflowProxies::processorCandidatesOf()})のどれかとして X を承認できるか。d に有効なユーザーが
     * いない・X が現在のノードでなければ、代理元を引かずに偽(表がどの P でも許さない)。
     */
    private function permitsSomeProcessor(
        Requester $requester,
        DateTimeInterface $date,
        WorkflowCase $case,
        int $designatorId,
    ): bool {
        $situationOf = fn (?int $processor) => $this->decider
            ->situationsOf($requester, $date, [$case], $processor)[$case->id];
        $own = $situationOf(null);
        $designator = $own->currentNode($designatorId);
        $userId = $own->processorUserId;

        if ($designator === null || $userId === null) {
            return false;
        }

        $flowId = $case->workflow_flow_id;

        foreach ($this->proxies->processorCandidatesOf($userId, $flowId, $designator->kind, $date) as $processor) {
            $situation = $processor === $userId ? $own : $situationOf($processor);

            if ($this->decider->permitsIn(WorkflowOperation::Approve, $requester, $date, $situation, $designatorId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 候補(申請基準日に有効なユーザー。並び = 共通マスタの口の並び)。{@see self::authorize()} を通った案件と Y を渡す。
     *
     * @return list<array{id: int, code: string, name: string|null, mainDepartmentName: string|null}>
     */
    public function of(AccessContext $context, int $caseId, int $nodeId, string $keyword): array
    {
        $case = WorkflowCase::query()->findOrFail($caseId);
        $designated = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->findOrFail($nodeId);
        $baseDate = $case->base_date ?? throw new LogicException("申請した案件 {$case->id} に申請基準日が無い");
        $excluded = $designated->prevents_applicant_approval ? $this->applicantGuard->applicantUserIds($case) : [];
        $users = $this->candidates->usersOn($keyword, $baseDate, $context->localeCode, self::LIMIT + count($excluded));

        return array_slice(array_values(array_filter($users, fn (array $user) => ! in_array($user['id'], $excluded, true))), 0, self::LIMIT);
    }
}
