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
 * 案件の詳細(docs の 設計/ワークフロー/画面/申請と案件.md 5 節の Props のうち読み取りの分)。閲覧の判定 {@see CaseVisibility} を最初に行い(見えない・一時保存 → 404)、
 * その後にだけ業務データの入れ物の主体の判定をしない口(表示・差分・関係)を呼ぶ(業務データとの接続/写しとつながる申請.md 9 節)。業務モジュールの read は求めない。
 *
 * 複数の読み取りを同じ改訂に揃えない: 案件の行を最初に読んでその改訂番号を返し、ノード・履歴・レコードはその後に読む(READ COMMITTED)。
 * 揃わないときの害は画面の一時的な混在だけで、操作は改訂番号で守られる。組み立ての最後に、案件の行を閲覧の判定を通してもう 1 回読み、見えなくなっていれば 404
 * (閲覧を失った後の履歴・コメントを返さない = 画面/申請と案件.md 5 節・認可と一覧.md 2 節)。組み立て直しは繰り返さず、返す改訂番号は最初に読んだ値。読み取りの間に閲覧を
 * 失って取り戻した場合は返す(受け入れた代償)。
 * 引戻し・取止めの入口の添付の可否と attachmentLimits = 設計/ワークフロー/申請の周辺/添付/README.md の 5 節({@see CaseAttachEntries})。
 * だれとして処理するか(処理担当者の選択肢・引戻しと取止めの処理者・代理の 1 行)は {@see CaseProcessorChoices}、確認の面は
 * {@see CaseConfirmationPanel}、基本情報の期限は {@see CaseDeadlines}、申請操作の面(can.operate と operation)は {@see CaseOperationPanel}
 * (設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節)。
 *
 * @phpstan-import-type Person from WorkflowPeople
 * @phpstan-import-type History from CaseHistories
 * @phpstan-import-type RelatedGroup from RelatedCases
 * @phpstan-import-type DiagramNode from CaseDiagram
 * @phpstan-import-type DiagramEdge from CaseDiagram
 * @phpstan-import-type Council from CaseCouncils
 * @phpstan-import-type AttachmentLimits from WorkflowAttachmentLimits
 * @phpstan-import-type DetailPullBackNode from CaseProcessorChoices
 * @phpstan-import-type Confirmation from CaseConfirmationPanel
 * @phpstan-import-type Panel from CaseOperationPanel
 *
 * @phpstan-type ShowCan array{pullBack: bool, reapply: bool, withdraw: bool, confirm: bool, operate: bool}
 * @phpstan-type Processing array{can: ShowCan,
 *     nodeActions: list<array<string, mixed>>, pullBackNodes: list<DetailPullBackNode>, casePrincipal: int|null,
 *     proxyNote: string|null, canAttachOnWithdraw: bool, attachmentLimits: AttachmentLimits|null}
 * @phpstan-type Detail array{case: array<string, mixed>, content: array<string, mixed>, nodes: list<DiagramNode>,
 *     edges: list<DiagramEdge>, councils: list<Council>, histories: list<History>,
 *     related: list<RelatedGroup>,
 *     can: ShowCan, operation: Panel|null,
 *     nodeActions: list<array<string, mixed>>, pullBackNodes: list<DetailPullBackNode>, casePrincipal: int|null,
 *     proxyNote: string|null,
 *     confirmations: list<Confirmation>, commentMaxLength: int, canAttachOnWithdraw: bool,
 *     attachmentLimits: AttachmentLimits|null}
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
        private CaseProcessorChoices $choices,
        private CaseDesignations $designations,
        private CaseDiagram $diagram,
        private CaseCouncils $councils,
        private CurrentAssignments $assignments,
        private CaseConfirmationPanel $confirmationPanel,
        private CaseDeadlines $deadlines,
        private WorkflowAttachmentLimits $limits,
        private CaseOperationPanel $operationPanel,
    ) {}

    /**
     * @return Detail
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できない・一時保存のとき(組み立ての後の 2 回目の閲覧の判定で見えなくなったときを含む)
     */
    public function show(AccessContext $context, int $caseId): array
    {
        $requester = Requester::fromAccessContext($context);
        $case = $this->visibleCase($requester, $context, $caseId)->load('workflowFlow.workflowContent');
        $recordId = $case->record_id ?? throw new LogicException("申請した案件 {$case->id} がレコードを指していない");
        $nodes = WorkflowCaseNode::query()->with('parent')->where('workflow_case_id', $case->id)->get()->sort(WorkflowCaseNode::compareInRoute(...))->values();
        $histories = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->orderBy('id')->get();
        $situation = $this->decider->situationsOf($requester, $context->date, [$case])[$case->id];
        $targets = array_merge([], ...array_map(fn (CurrentNode $node) => $node->targetUserIds, $situation->currentNodes));
        $peopleIds = [$case->user_id, ...$targets, ...self::actorIdsOf($histories)];
        $people = $this->people->users($peopleIds, $context->date, $context->localeCode);
        $content = $this->display->show($requester, $recordId, $context->date, $context->localeCode);
        $historiesById = $histories->keyBy('id');
        $assignments = $nodes->contains(fn (WorkflowCaseNode $node) => $node->designator_workflow_case_node_id !== null) ? $this->assignments->of($case->id) : [];
        $councils = $this->councils->of($case, $nodes, $historiesById, $assignments, $situation, $requester->userId, $context->date, $context->localeCode);
        $diagram = $this->diagram->of($case, $nodes, $historiesById, $assignments, $situation, $people, $councils, $requester->userId);
        $updatedAfter = $this->updatedAfterSubmission($content['record']['updatedAt'], $histories);
        $operation = $this->operationPanel->of($requester, $context, $situation, $nodes);
        $detail = [
            'case' => $this->caseOf($case, $situation, $people, $context),
            'content' => [...$content, 'updatedAfterSubmission' => $updatedAfter],
            'nodes' => $diagram['nodes'],
            'edges' => $diagram['edges'],
            'councils' => array_values($councils),
            'histories' => $this->histories
                ->of($requester, $context->date, $context->localeCode, $histories, $nodes->keyBy('id'), $people),
            'related' => $this->related->of($requester, $context->date, $context->localeCode, $case, $content),
            ...$this->processingOf($requester, $context, $situation, $nodes, $operation !== null),
            'operation' => $operation,
            'confirmations' => $this->confirmationPanel
                ->of($case, $nodes, $context->userId, $context->date, $context->localeCode),
            'commentMaxLength' => WorkflowCaseHistory::COMMENT_MAX,
        ];
        $this->visibleCase($requester, $context, $case->id);

        return $detail;
    }

    /**
     * 処理の操作の props(can・nodeActions と指定の欄・引戻せるノード・casePrincipal・代理の 1 行・添付の可否)。
     *
     * @param  Collection<int, WorkflowCaseNode>  $nodes  写した並びのノード
     * @param  bool  $canOperate  申請操作の面を出すか(面のボタンのどれかが真 = 認可と一覧.md の 3 節)
     * @return Processing
     */
    private function processingOf(
        Requester $requester,
        AccessContext $context,
        CaseSituation $situation,
        Collection $nodes,
        bool $canOperate,
    ): array {
        $date = $context->date;
        $kinds = array_map(fn (WorkflowCaseNode $node) => $node->kind, $nodes->keyBy('id')->all());
        $choices = $this->choices->of($requester, $context, $situation, $kinds);
        $approvable = array_filter($choices['nodeActions'], fn (array $action) => $action['approve']);
        $approveIds = array_values(array_map(fn (array $action) => $action['nodeId'], $approvable));
        $designations = $this->designations->of($situation->case->id, $approveIds, $date, $context->localeCode);
        $designated = fn (array $action) => [...$action, 'designations' => $designations[$action['nodeId']] ?? []];
        $pullBackAttach = array_column($choices['pullBackNodes'], 'canAttach');
        $attachable = CaseNodeActions::anyCanAttach($choices['nodeActions'])
            || in_array(true, [$choices['canAttachOnWithdraw'], ...$pullBackAttach], true);

        return [
            'can' => [
                ...$choices['can'],
                'confirm' => $this->decider->canConfirm($requester, $date, $situation),
                'operate' => $canOperate,
            ],
            'nodeActions' => array_map($designated, $choices['nodeActions']),
            'pullBackNodes' => $choices['pullBackNodes'],
            'casePrincipal' => $choices['casePrincipal'],
            'proxyNote' => $choices['proxyNote'],
            'canAttachOnWithdraw' => $choices['canAttachOnWithdraw'],
            'attachmentLimits' => $this->limits->forPage($attachable),
        ];
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
            'deadline' => $case->state === WorkflowCaseState::Active
                ? $this->deadlines->viewOf($situation->currentNodes, $context->date)
                : null,
            'revision' => $case->revision,
        ];
    }

    /**
     * 履歴の処理者と実行者(処理実行者の表示 = 設計/ワークフロー/案件の運用/画面/README.md の 8.2 節)。
     *
     * @param  Collection<int, WorkflowCaseHistory>  $histories
     * @return list<int>
     */
    private static function actorIdsOf(Collection $histories): array
    {
        $ids = [];

        foreach ($histories as $history) {
            $executor = $history->executor_user_id;
            $ids = [...$ids, $history->user_id, ...($executor === null ? [] : [$executor])];
        }

        return $ids;
    }

    /**
     * レコードが、最後の提出(申請・再申請)の履歴の行の後に更新されたか(秒の精度で比べ、同じ秒は変更なし = 画面/申請と案件.md 5 節)。レコードの更新日時は表示の口
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
