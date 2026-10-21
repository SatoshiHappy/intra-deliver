<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 案件の詳細(docs の 設計/ワークフロー/画面.md 5 節の Props のうち読み取りの分)。閲覧の判定 {@see CaseVisibility} を最初に行い(見えない・一時保存 → 404)、
 * その後にだけ業務データの入れ物の主体の判定をしない口(表示・差分・関係)を呼ぶ(業務データとの接続/README.md 9 節)。業務モジュールの read は求めない。
 *
 * 複数の読み取りを同じ改訂に揃えない: 案件の行を最初に読んでその改訂番号を返し、ノード・履歴・レコードはその後に読む(READ COMMITTED)。
 * 揃わないときの害は画面の一時的な混在だけで、操作は改訂番号で守られる。組み立ての最後に、案件の行を閲覧の判定を通してもう 1 回読み、見えなくなっていれば 404
 * (閲覧を失った後の履歴・コメントを返さない = 画面.md 5 節・認可と一覧.md 2 節)。組み立て直しは繰り返さず、返す改訂番号は最初に読んだ値。読み取りの間に閲覧を
 * 失って取り戻した場合は返す(受け入れた代償)。
 * 引戻し・取止めの入口の添付の可否と attachmentLimits = 設計/ワークフロー/申請の周辺/添付/README.md の 5 節({@see CaseAttachEntries})。
 *
 * @phpstan-import-type Person from WorkflowPeople
 * @phpstan-import-type History from CaseHistories
 * @phpstan-import-type CaseRow from CaseRows
 * @phpstan-import-type DiagramNode from CaseDiagram
 * @phpstan-import-type DiagramEdge from CaseDiagram
 * @phpstan-import-type Council from CaseCouncils
 * @phpstan-import-type PullBackNode from CaseRows
 * @phpstan-import-type AttachmentLimits from WorkflowAttachmentLimits
 */
class CaseDetailQuery
{
    public function __construct(
        private CaseVisibility $visibility,
        private CaseOperationDecider $decider,
        private WorkflowPeople $people,
        private CaseHistories $histories,
        private RelatedCases $related,
        private RecordDisplayQuery $display,
        private CaseNodeActions $nodeActions,
        private CaseDesignations $designations,
        private CaseDiagram $diagram,
        private CaseCouncils $councils,
        private CurrentAssignments $assignments,
        private CaseAttachEntries $attachEntries,
        private WorkflowAttachmentLimits $limits,
    ) {}

    /**
     * @return array{case: array<string, mixed>, content: array<string, mixed>, nodes: list<DiagramNode>, edges: list<DiagramEdge>, councils: list<Council>, histories: list<History>, related: array{sameRecord: list<CaseRow>, referenced: list<CaseRow>, referencing: list<CaseRow>}, can: array{pullBack: bool, reapply: bool, withdraw: bool}, nodeActions: list<array<string, mixed>>, pullBackNodes: list<PullBackNode>, commentMaxLength: int, canAttachOnWithdraw: bool, attachmentLimits: AttachmentLimits|null}
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できない・一時保存のとき(組み立ての後の 2 回目の閲覧の判定で見えなくなったときを含む)
     */
    public function show(AccessContext $context, int $caseId): array
    {
        $requester = Requester::fromAccessContext($context);
        $case = $this->visibleCase($requester, $context, $caseId)->load('workflowFlow');
        $recordId = $case->record_id ?? throw new LogicException("申請した案件 {$case->id} がレコードを指していない");
        $nodes = WorkflowCaseNode::query()->with('parent')->where('workflow_case_id', $case->id)->get()->sort(WorkflowCaseNode::compareInRoute(...))->values();
        $histories = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')->get();
        $situation = $this->decider->situationsOf($requester, $context->date, [$case])[$case->id];
        $targets = array_merge([], ...array_map(fn (CurrentNode $node) => $node->targetUserIds, $situation->currentNodes));
        $people = $this->people->users([$case->user_id, ...$targets, ...array_values($histories->map(fn (WorkflowCaseHistory $history) => $history->user_id)->all())], $context->date, $context->localeCode);
        $nodeActions = $this->nodeActions->of($requester, $context->date, $situation);
        $can = $this->decider->detailCanOf($requester, $context->date, $situation);
        $attach = $this->attachEntries->of([$situation], [$case->id => $can['withdraw']])[$case->id];
        $attachable = in_array(true, [$attach['withdraw'], ...$attach['pullBack']], true);
        $designations = $this->designations->of($case->id, array_values(array_map(fn (array $action) => $action['nodeId'], array_filter($nodeActions, fn (array $action) => $action['approve']))), $context->date, $context->localeCode);
        $content = $this->display->show($requester, $recordId, $context->date, $context->localeCode);
        $historiesById = $histories->keyBy('id');
        $assignments = $nodes->contains(fn (WorkflowCaseNode $node) => $node->designator_workflow_case_node_id !== null) ? $this->assignments->of($case->id) : [];
        $councils = $this->councils->of($case, $nodes, $historiesById, $assignments, $situation, $requester->userId, $context->date, $context->localeCode);
        $diagram = $this->diagram->of($case, $nodes, $historiesById, $assignments, $situation, $people, $councils, $requester->userId);
        $detail = [
            'case' => $this->caseOf($case, $situation, $people, $context),
            'content' => [...$content, 'updatedAfterSubmission' => $this->updatedAfterSubmission($content['record']['updatedAt'], $histories)],
            'nodes' => $diagram['nodes'],
            'edges' => $diagram['edges'],
            'councils' => array_values($councils),
            'histories' => $this->histories
                ->of($requester, $context->date, $context->localeCode, $histories, $nodes->keyBy('id'), $people),
            'related' => $this->related->of($requester, $context->date, $context->localeCode, $case, $recordId),
            'can' => $can,
            'nodeActions' => array_map(fn (array $action) => [...$action, 'designations' => $designations[$action['nodeId']] ?? []], $nodeActions),
            'pullBackNodes' => CaseRows::pullBackNodesOf($situation, $attach['pullBack']),
            'commentMaxLength' => WorkflowCaseHistory::COMMENT_MAX,
            'canAttachOnWithdraw' => $attach['withdraw'],
            'attachmentLimits' => $this->limits->forPage($attachable),
        ];
        $this->visibleCase($requester, $context, $case->id);

        return $detail;
    }

    /**
     * 閲覧できる一時保存でない案件の行(閲覧の判定 {@see CaseVisibility})。
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できない・一時保存のとき
     */
    private function visibleCase(Requester $requester, AccessContext $context, int $caseId): WorkflowCase
    {
        return $this->visibility->visible(WorkflowCase::query()->whereKey($caseId)->where('state', '<>', WorkflowCaseState::Draft), $requester, $context->date)->firstOrFail();
    }

    /**
     * @param  array<int, Person>  $people
     * @return array<string, mixed>
     */
    private function caseOf(WorkflowCase $case, CaseSituation $situation, array $people, AccessContext $context): array
    {
        $flow = CaseRows::flowOf($case);

        return [
            'id' => $case->id,
            'number' => CaseNumbers::format($case->case_number ?? throw new LogicException("申請した案件 {$case->id} が案件番号を持たない")),
            'name' => $case->name ?? throw new LogicException("申請した案件 {$case->id} が案件名を持たない"),
            'state' => $case->state->value,
            'completionReason' => $case->completion_reason?->value,
            'flow' => ['code' => $flow->code, 'name' => $flow->name],
            'applicant' => $people[$case->user_id],
            'department' => $case->department_id === null ? null : $this->people->departments([$case->department_id], $context->date, $context->localeCode)[$case->department_id],
            'appliedAt' => $case->applied_at?->toIso8601String(),
            'completedAt' => $case->completed_at?->toIso8601String(),
            'baseDate' => $case->base_date?->format(ApplicationBaseDates::FORMAT),
            'version' => $case->workflowFlowVersion()->sole()->number,
            'currentSummary' => CaseRows::currentSummaryOf($situation),
            'revision' => $case->revision,
        ];
    }

    /**
     * レコードが、最後の提出(申請・再申請)の履歴の行の後に更新されたか(秒の精度で比べ、同じ秒は変更なし = 画面.md 5 節)。レコードの更新日時は表示の口
     * {@see RecordDisplayQuery::show()} が返した値(ISO 8601 の文字列、秒まで = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節)を日時に読む
     * (業務データの入れ物の表を直接読まない)。
     *
     * @param  string|null  $recordUpdatedAt  表示の口の record.updatedAt
     * @param  Collection<int, WorkflowCaseHistory>  $histories
     */
    private function updatedAfterSubmission(?string $recordUpdatedAt, Collection $histories): bool
    {
        $submittedAt = $histories->last(fn (WorkflowCaseHistory $history) => in_array($history->action, CaseHistories::SUBMISSIONS, true))?->created_at;

        return $submittedAt !== null && $recordUpdatedAt !== null && CarbonImmutable::parse($recordUpdatedAt)->getTimestamp() > $submittedAt->getTimestamp();
    }
}
