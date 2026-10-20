<?php

namespace Tests\Support;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\SendBackCase;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\CaseVisibility;

/**
 * 計画に結んだ案件の操作の業務処理を、ノードの名札({@see RunsRoutePlans::labelIds()})で呼ぶ手助け(docs の 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 14 節)。
 * 申請・承認・承認終了・否認・差戻し・引戻しの後に不変の条件({@see RunsRoutePlans::assertRouteInvariants()})を確かめ、操作の結果の値と一覧への出方を読む。
 */
trait OperatesOnRoutes
{
    use RunsRoutePlans;

    /**
     * 本物の申請の業務処理で申請する(申請者 u0001、申請基準日 = 操作の基準日 = 最後に足した版)。
     *
     * @param  array<string, mixed>  $values  業務モジュール project の値に重ねるもの
     * @return array{WorkflowCase, CaseOperationResult}
     */
    protected function applyOnRoute(array $values): array
    {
        $result = app(ApplyCase::class)->execute($this->contextOf($this->seededUser('u0001')), self::FLOW_CODE, $this->projectInput($values), $this->operationDay()->toDateString());
        $case = WorkflowCase::query()->findOrFail($result->caseId);
        $this->assertRouteInvariants($case);

        return [$case, $result];
    }

    /**
     * @param  array<string, list<string>>  $assignments  指定されるノードの名札 → 人のコード
     */
    protected function approve(WorkflowCase $case, string $label, string $userCode, array $assignments = []): CaseOperationResult
    {
        $ids = $this->labelIds($case);
        $byNode = [];

        foreach ($assignments as $code => $userCodes) {
            $byNode[$ids[$code]] = array_map(fn (string $userCode) => $this->seededUser($userCode)->id, $userCodes);
        }

        $result = app(ApproveCase::class)->execute($this->contextOf($this->seededUser($userCode)), $case->id, $this->revisionOf($case), $ids[$label], null, $byNode);
        $this->assertRouteInvariants($case);

        return $result;
    }

    /**
     * 差戻し(操作するノード = $label、差戻し先 = $toLabel)。
     */
    protected function sendBackOn(WorkflowCase $case, string $label, string $userCode, string $toLabel): CaseOperationResult
    {
        $ids = $this->labelIds($case);
        $result = app(SendBackCase::class)->execute($this->contextOf($this->seededUser($userCode)), $case->id, $this->revisionOf($case), $ids[$label], $ids[$toLabel], null);
        $this->assertRouteInvariants($case);

        return $result;
    }

    /**
     * 引戻し(引戻すノード = $label)。
     */
    protected function pullBackOn(WorkflowCase $case, string $label, string $userCode): CaseOperationResult
    {
        $result = app(PullBackCase::class)->execute($this->contextOf($this->seededUser($userCode)), $case->id, $this->revisionOf($case), $this->labelIds($case)[$label], null);
        $this->assertRouteInvariants($case);

        return $result;
    }

    protected function reject(WorkflowCase $case, string $label, string $userCode): CaseOperationResult
    {
        return $this->operateOn(RejectCase::class, $case, $label, $userCode);
    }

    /**
     * @param  class-string<ApproveCaseEarly|RejectCase>  $operation
     */
    protected function operateOn(string $operation, WorkflowCase $case, string $label, string $userCode): CaseOperationResult
    {
        $result = app($operation)->execute($this->contextOf($this->seededUser($userCode)), $case->id, $this->revisionOf($case), $this->labelIds($case)[$label], null);
        $this->assertRouteInvariants($case);

        return $result;
    }

    /**
     * 操作の結果の値(11 節): 案件・操作・履歴の行(操作した人がその種別で書いた最新の行)・到達したノードと処理対象者・完了の理由・操作した人
     * (3a = 処理者 = 実行者 = 操作した人)。期待は操作した人のコードと操作の種別から作る。
     *
     * @param  string  $actorCode  操作した人のコード
     * @param  array<string, list<string>>  $reached  名札 → 人のコード
     */
    protected function assertResult(WorkflowCase $case, CaseOperationResult $result, WorkflowAction $action, string $actorCode, array $reached, ?WorkflowCompletionReason $completion): void
    {
        $labels = array_flip($this->labelIds($case));
        $codes = User::query()->pluck('code', 'id');
        $actorId = $this->seededUser($actorCode)->id;
        $history = WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)->where('user_id', $actorId)->where('action', $action)->orderByDesc('id')->firstOrFail();
        $actual = [];

        foreach ($result->reachedNodes as $nodeId => $userIds) {
            $actual[$labels[$nodeId]] = array_map(fn (int $userId) => $codes[$userId], $userIds);
        }

        ksort($actual);
        ksort($reached);
        $this->assertSame([$case->id, $action, $history->id, $reached, $completion], [$result->caseId, $result->action, $result->historyId, $actual, $result->completionReason]);
        $this->assertEquals(new CaseActor($actorId, $actorId), $result->actor);
    }

    /**
     * 各人の 4 タブの述語のうち、その案件が出るもの(認可と一覧.md 1 節)。
     *
     * @return array<string, list<string>>
     */
    protected function appearances(WorkflowCase $case, string ...$userCodes): array
    {
        $visibility = app(CaseVisibility::class);
        $result = [];

        foreach ($userCodes as $code) {
            $requester = new Requester($this->seededUser($code)->id, null);
            $result[$code] = array_values(array_filter(['draft', 'pending', 'processed', 'referenced'], fn (string $predicate) => $visibility->{$predicate}(WorkflowCase::query()->whereKey($case->id), $requester, $this->operationDay())->exists()));
        }

        return $result;
    }

    /**
     * @return list<array{int, string, int, string}> [履歴の行, 指定されたノードの名札, 番号, 人のコード]
     */
    protected function assignmentRows(WorkflowCase $case): array
    {
        $labels = array_flip($this->labelIds($case));
        $codes = User::query()->pluck('code', 'id');

        return array_values(WorkflowCaseAssignment::query()->where('workflow_case_id', $case->id)->orderBy('workflow_case_node_id')->orderBy('sequence')->get()
            ->map(fn (WorkflowCaseAssignment $row) => [$row->workflow_case_history_id, $labels[$row->workflow_case_node_id], $row->sequence, (string) $codes[$row->user_id]])->all());
    }

    /**
     * @return list<string> 縦配置の親と子(番号の順)の形
     */
    protected function councilShape(WorkflowCase $case): array
    {
        return $this->familyShape($case, 'council');
    }

    /**
     * @return list<string> 横配置の親と子(番号の順)の形
     */
    protected function lineShape(WorkflowCase $case): array
    {
        return $this->familyShape($case, 'line');
    }

    /**
     * @return list<string>
     */
    protected function familyShape(WorkflowCase $case, string $parent): array
    {
        return array_values(array_filter($this->shape($case), fn (string $label) => $label === $parent || str_starts_with($label, "{$parent}."), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @return array<string, string>
     */
    protected function pick(WorkflowCase $case, string ...$labels): array
    {
        return array_intersect_key($this->shape($case), array_flip($labels));
    }
}
