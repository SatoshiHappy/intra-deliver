<?php

namespace Tests\Support;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Records\Api\RecordDetailRowInput;
use App\Actions\Records\Api\RecordInput;
use App\Actions\Workflow\ApplicationInput;
use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CreateWorkflowContent;
use App\Actions\Workflow\CreateWorkflowFlow;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\ReapplyCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\WithdrawCase;
use App\Actions\Workflow\WorkflowRouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Models\Authz\Role;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Notice\Notice;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowCaseHistoryUser;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Models\Workflow\WorkflowContent;
use App\Queries\Workflow\PullBackableNodes;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * 案件の操作の業務処理のテストの手助け(docs の 設計/ワークフロー/案件の操作/同時実行と検査.md 7 節)。定義は 4a の定義の業務処理で、案件は本物の業務処理で作る。
 * 既定のフロー = 初期データ.md 3 節の「プロジェクト計画の承認」と同じ形(申請 = 一般利用者のロール(初期データの全員)、承認 1 = u0002、承認 2 = u0003、
 * 参照者 = 経理部)。人・組織は初期データ(u0001〜)を使う。初期データのワークフローの定義・案件と混ざらないように、コンテンツとフローはテスト用のコード
 * ({@see self::CONTENT_CODE}・{@see self::FLOW_CODE})で作る(自分が作った行だけを見る)。
 */
trait RunsWorkflowOperations
{
    /** 既定のフローのコード(初期データの project_plan とは別) */
    protected const FLOW_CODE = 'wf_plan';

    /** テストのフローが使うコンテンツのコード(業務モジュール project。初期データの project_plan とは別) */
    protected const CONTENT_CODE = 'wf_project';

    /** テストのフローの版 1 の開始日(操作の基準日 {@see self::operationDay()} と、過去の基準日で申請するテスト(2025-09-01)を含む) */
    protected const FLOW_START_DATE = '2025-04-01';

    /** 申請できる人の判定が読む申請のノード(ApplicableFlows)の SQL */
    protected const APPLY_NODE_SQL = '/from "workflow_route_nodes" where .*"position" = /';

    /** 業務モジュールの update の判定の結合(UpdatableFlows)の SQL */
    protected const UPDATE_JUDGEMENT_SQL = '/select "record_modules"\\."code", "workflow_flows"\\."id" from "workflow_flows"/';

    /** {@see self::recordedSql()} の listener を登録したか(DB::listen は外せないので、テストごとに 1 つだけ登録し、記録中の旗で止める) */
    private bool $listeningSql = false;

    private bool $recordingSql = false;

    /** @var list<string> */
    private array $recordedSqlLog = [];

    /** 操作の基準日 d(初期データの退職 2025-10-01・入社 2026-11-01 の間) */
    protected function operationDay(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-10-13');
    }

    /**
     * 業務データの入れ物の業務モジュール project のフロー(版 1 = {@see self::FLOW_START_DATE} から上限なし。ルートはフローごとに作る)。
     *
     * @param  list<list<SelectionCondition>>|null  $approvals  承認のノードごとの処理対象者の条件(null = 承認 1 = u0002、承認 2 = u0003)
     * @param  list<SelectionCondition>|null  $applicants  申請のノードの条件(null = 一般利用者のロール)
     */
    protected function createFlow(string $code = self::FLOW_CODE, string $name = 'プロジェクト計画の承認', ?array $approvals = null, ?array $applicants = null): int
    {
        if (! WorkflowContent::query()->where('code', self::CONTENT_CODE)->exists()) {
            app(CreateWorkflowContent::class)->execute('project', self::CONTENT_CODE, 'プロジェクト計画');
        }

        $approvals ??= [[$this->userCondition('u0002')], [$this->userCondition('u0003')]];
        $nodes = [new WorkflowRouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, $applicants ?? [new SelectionCondition(SelectionConditionKind::Role, Role::query()->where('code', 'general_user')->sole()->id)], ['ja' => '申請'])];

        foreach ($approvals as $index => $conditions) {
            $position = $index + 1;
            $nodes[] = new WorkflowRouteNodeInput("approval{$position}", "承認{$position}", WorkflowNodeKind::Approve, $conditions, ['ja' => "承認{$position}"]);
        }

        return app(CreateWorkflowFlow::class)->execute($code, $name, null, self::CONTENT_CODE, $nodes, CarbonImmutable::parse(self::FLOW_START_DATE), [new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('accounting'))], ['ja' => '参照者'], 10);
    }

    /**
     * 操作の判定の入力(ゲストは null。基準日の既定は {@see self::operationDay()})。
     */
    protected function contextOf(?User $user, ?int $selectedDepartmentId = null, ?string $date = null): AccessContext
    {
        return new AccessContext($user?->id, null, $selectedDepartmentId, $date === null ? $this->operationDay() : CarbonImmutable::parse($date), AccessContext::LOCALE_CODE);
    }

    /**
     * 業務モジュール project の正しい入力(マイルストーン 1 行)。
     *
     * @param  array<string, mixed>  $values  上書きする値
     */
    protected function projectInput(array $values = [], ?string $name = null, ?string $comment = null): ApplicationInput
    {
        return new ApplicationInput($name, $comment, new RecordInput([
            'name' => '新製品の企画', 'department' => $this->departmentIdOf('tokyo-sales'), 'manager' => $this->seededUser('u0002')->id, 'status' => 'planning',
            'start_date' => '2026-11-01', ...$values,
        ], ['milestones' => [new RecordDetailRowInput(values: ['name' => '要件の整理', 'due_date' => '2026-11-30'])]]));
    }

    /**
     * 本物の業務処理で申請する(新規)。申請基準日の既定 = 操作の基準日(画面の初期値 = 今日と同じ)。
     */
    protected function applyAs(User $applicant, string $flowCode = self::FLOW_CODE, ?ApplicationInput $input = null, ?string $date = null, ?string $baseDate = null): WorkflowCase
    {
        $context = $this->contextOf($applicant, date: $date);

        return WorkflowCase::query()->findOrFail(app(ApplyCase::class)->execute($context, $flowCode, $input ?? $this->projectInput(), $baseDate ?? $context->date->toDateString())->caseId);
    }

    /**
     * 処理の操作を今の改訂番号で行う(承認・承認終了・否認 = 操作するノード({@see self::operatedNodeId()})とコメント、引戻し = 引戻すノード
     * ({@see self::pullBackNodeId()})とコメント、取止め = コメントだけ)。
     *
     * @param  class-string<ApproveCase|ApproveCaseEarly|RejectCase|PullBackCase|WithdrawCase>  $operation
     */
    protected function operate(string $operation, ?User $user, WorkflowCase $case, ?string $comment = null, ?string $date = null): void
    {
        $context = $this->contextOf($user, date: $date);

        match ($operation) {
            ApproveCase::class, ApproveCaseEarly::class, RejectCase::class => app($operation)->execute($context, $case->id, $this->revisionOf($case), $this->operatedNodeId($case->id, $user?->id), $comment),
            PullBackCase::class => app($operation)->execute($context, $case->id, $this->revisionOf($case), $this->pullBackNodeId($case->id, $user?->id), $comment),
            default => app($operation)->execute($context, $case->id, $this->revisionOf($case), $comment),
        };
    }

    protected function sendBack(?User $user, WorkflowCase $case, string $toNodeCode, ?string $comment = null, ?string $date = null): void
    {
        app(SendBackCase::class)->execute($this->contextOf($user, date: $date), $case->id, $this->revisionOf($case), $this->operatedNodeId($case->id, $user?->id), $this->caseNode($case, $toNodeCode)->id, $comment);
    }

    /**
     * 段 2 の画面と同じ node_id: 現在のノードのうち、その人が処理対象者のもの(無ければ最初の現在のノード、現在のノードが無ければ申請のノード = 表で拒まれる。
     * ノードの無い一時保存は 0 = この案件のノードでない)。
     */
    protected function operatedNodeId(int $caseId, ?int $userId): int
    {
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->whereIn('state', WorkflowNodeState::current())->orderBy('id')->get();
        $own = $userId === null ? null : $nodes->first(fn (WorkflowCaseNode $node) => WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $node->id)->where('user_id', $userId)->exists());

        return ($own ?? $nodes->first() ?? WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('position', 1)->first())->id ?? 0;
    }

    /**
     * 画面と同じ引戻しの node_id: その人が引戻せるノードの先頭(無ければ最新の履歴の行のノード = 表で拒まれる。履歴の無い一時保存は 0 = この案件のノードでない)。
     */
    protected function pullBackNodeId(int $caseId, ?int $userId): int
    {
        $nodes = $userId === null ? [] : app(PullBackableNodes::class)->of([$caseId], $userId)[$caseId];

        return ($nodes[0] ?? null)->id ?? (int) WorkflowCaseHistory::query()->where('workflow_case_id', $caseId)->orderByDesc('id')->value('workflow_case_node_id');
    }

    protected function reapply(?User $user, WorkflowCase $case, ?ApplicationInput $input = null, ?string $date = null): void
    {
        app(ReapplyCase::class)->execute($this->contextOf($user, date: $date), $case->id, $this->revisionOf($case), $input ?? $this->projectInput());
    }

    protected function revisionOf(WorkflowCase $case): int
    {
        return WorkflowCase::query()->findOrFail($case->id)->revision;
    }

    protected function caseNode(WorkflowCase $case, string $code): WorkflowCaseNode
    {
        return WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->where('code', $code)->sole();
    }

    /**
     * 未完了の案件は現在のノードをちょうど 1 つ持ち、それより前の位置はすべて処理済み。完了の案件は現在のノードを持たない(README 1.2 の不変の条件)。
     */
    protected function assertNodeInvariant(WorkflowCase $case): void
    {
        $case = WorkflowCase::query()->findOrFail($case->id);
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->orderBy('position')->get();
        $current = $nodes->filter(fn (WorkflowCaseNode $node) => $node->state->isCurrent())->values();

        if ($case->state !== WorkflowCaseState::Active) {
            $this->assertCount(0, $current);
            $this->assertSame([], WorkflowCaseNodeTarget::query()->whereIn('workflow_case_node_id', $nodes->modelKeys())->pluck('id')->all());

            return;
        }

        $this->assertCount(1, $current);
        $currentNode = $current->sole();
        $before = $nodes->filter(fn (WorkflowCaseNode $node) => $node->position < $currentNode->position);
        $this->assertSame($before->map(fn () => WorkflowNodeState::Done)->values()->all(), $before->map(fn (WorkflowCaseNode $node) => $node->state)->values()->all());
        $this->assertSame([], WorkflowCaseNodeTarget::query()->whereIn('workflow_case_node_id', $nodes->where('id', '!=', $currentNode->id)->modelKeys())->pluck('id')->all());
    }

    protected function seededUser(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    protected function departmentIdOf(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    protected function userCondition(string $userCode): SelectionCondition
    {
        return new SelectionCondition(SelectionConditionKind::User, $this->seededUser($userCode)->id);
    }

    /**
     * 案件のノードの今の形(位置の順。縦配置の子は位置が無い(null)ので id の順で決める): [コード(展開の行は null), 状態, 指す履歴の種別か null, 処理対象者の id の昇順]。
     *
     * @return list<array{string|null, string, string|null, list<int>}>
     */
    protected function nodeStates(WorkflowCase $case): array
    {
        $nodes = WorkflowCaseNode::query()->where('workflow_case_id', $case->id)->orderBy('position')->orderBy('id')->get();
        $actions = WorkflowCaseHistory::query()->whereIn('id', $nodes->pluck('workflow_case_history_id')->filter()->all())->get()->mapWithKeys(fn (WorkflowCaseHistory $history) => [$history->id => $history->action->value]);
        $targets = WorkflowCaseNodeTarget::query()->whereIn('workflow_case_node_id', $nodes->modelKeys())->orderBy('user_id')->get()->groupBy('workflow_case_node_id');

        return array_values($nodes->map(fn (WorkflowCaseNode $node) => [
            $node->code,
            $node->state->value,
            $node->workflow_case_history_id === null ? null : ($actions[$node->workflow_case_history_id] ?? throw new LogicException("history {$node->workflow_case_history_id} missing")),
            array_values(array_map(fn (WorkflowCaseNodeTarget $target) => (int) $target->user_id, $targets->get($node->id)?->all() ?? [])),
        ])->all());
    }

    /**
     * 案件・ノード・処理対象者・履歴・履歴の人・レコード・確認対象者・確認・通知の行の数(失敗で何も変わらないことの確かめ)。
     *
     * @return array<string, int>
     */
    protected function workflowCounts(): array
    {
        return [
            'cases' => WorkflowCase::query()->count(),
            'nodes' => WorkflowCaseNode::query()->count(),
            'targets' => WorkflowCaseNodeTarget::query()->count(),
            'histories' => WorkflowCaseHistory::query()->count(),
            'history_users' => WorkflowCaseHistoryUser::query()->count(),
            'records' => Record::query()->count(),
            'confirm_targets' => WorkflowCaseConfirmTarget::query()->count(),
            'confirmations' => WorkflowCaseConfirmation::query()->count(),
            'notices' => Notice::query()->count(),
        ];
    }

    /**
     * 処理の間に出た SQL(小文字)。listener はテストごとに 1 つで、処理の外では記録しない。
     *
     * @param  Closure(): mixed  $action
     * @return list<string>
     */
    protected function recordedSql(Closure $action): array
    {
        if (! $this->listeningSql) {
            DB::listen(function (QueryExecuted $query) {
                if ($this->recordingSql) {
                    $this->recordedSqlLog[] = strtolower($query->sql);
                }
            });
            $this->listeningSql = true;
        }

        $this->recordedSqlLog = [];
        $this->recordingSql = true;

        try {
            $action();
        } finally {
            $this->recordingSql = false;
        }

        return $this->recordedSqlLog;
    }

    /**
     * 連想配列のキーを中まで並べ替える(jsonb はキーの順を保たないので、保存した JSON と比べるときに使う)。
     */
    protected function sortedKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn (mixed $item) => $this->sortedKeys($item), $value);
    }
}
