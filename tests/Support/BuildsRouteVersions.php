<?php

namespace Tests\Support;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\AddRouteVersion;
use App\Actions\Workflow\CaseRouteCopier;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowDecisionRule;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Master\Api\CurrentDepartment;
use Carbon\CarbonImmutable;

/**
 * 形つきの版(ルートの見本)と、その版を写した案件を作る手助け(docs の 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 14 節の「ルートの見本」)。
 * 版は {@see AddRouteVersion} で、テストのフロー({@see RunsWorkflowOperations::createFlow()} = 版 1 は段 2 の一本道 R4)の最後に足す(開始日 {@see self::ROUTE_START})。
 * 案件は行を作って版を写す({@see CaseRouteCopier}。申請の業務処理は通さない)。人は初期データ(申請者 u0001 = 東京営業課)。
 */
trait BuildsRouteVersions
{
    use RunsWorkflowOperations;

    /** 足す版の開始日(案件の申請基準日 = 操作の基準日 2026-10-13 を含む) */
    protected const ROUTE_START = '2026-01-01';

    /**
     * R1 = 初期データの分岐 B と同じ形(申請 → 課長 → 分岐「予算」(部長 / 何もしない)→ 縦配置(合議。人数 2、2〜5 人、防止オン)→ 責任者(動的承認、1〜1 人))。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    protected function r1(): array
    {
        $front = ['apply', 'section_chief'];

        return [[
            $this->applyNode(),
            new RouteNodeInput('section_chief', '課長', WorkflowNodeKind::Approve, WorkflowTargetKind::Relative, relativeBase: WorkflowRelativeBase::Applicant, relativeScope: WorkflowRelativeScope::Own, companyPostId: $this->postId('section-chief'), preventsApplicantApproval: true, sendBackCodes: ['apply']),
            new RouteNodeInput('budget', '予算', WorkflowNodeKind::BranchStart, closingCode: 'budget_end'),
            new RouteNodeInput('manager', '部長', WorkflowNodeKind::Approve, WorkflowTargetKind::Relative, relativeBase: WorkflowRelativeBase::Applicant, relativeScope: WorkflowRelativeScope::Parent, companyPostId: $this->postId('manager'), preventsApplicantApproval: true, sendBackCodes: $front),
            new RouteNodeInput('budget_end', '分岐終了', WorkflowNodeKind::BranchEnd),
            new RouteNodeInput('council', '関係部署の合議', WorkflowNodeKind::Parallel, WorkflowTargetKind::Designated, designatorCode: 'section_chief', minAssignees: 2, maxAssignees: 5, decisionRule: WorkflowDecisionRule::Count, decisionValue: 2, preventsApplicantApproval: true, sendBackCodes: $front),
            new RouteNodeInput('owner', '責任者', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated, designatorCode: 'section_chief', minAssignees: 1, maxAssignees: 1, preventsApplicantApproval: true, sendBackCodes: $front),
        ], [
            new RouteEdgeInput('apply', 'section_chief'),
            new RouteEdgeInput('section_chief', 'budget'),
            new RouteEdgeInput('budget', 'manager', 1, '予算 300 万円以上', ['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]]),
            new RouteEdgeInput('budget', 'budget_end', 2, '何もしない', ['all' => [['field' => 'budget', 'op' => 'lt', 'value' => 300]]]),
            new RouteEdgeInput('manager', 'budget_end'),
            new RouteEdgeInput('budget_end', 'council'),
            new RouteEdgeInput('council', 'owner'),
        ]];
    }

    /**
     * R2 = 同期で 2 つの枝(枝 A = 承認 a1(u0002)→ a2(u0003)、枝 B = 承認 b1(u0004))を待ち、最後に final(u0010)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    protected function r2(): array
    {
        return [[
            $this->applyNode(),
            new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'),
            $this->approveNode('a1', 'u0002', ['apply']),
            $this->approveNode('a2', 'u0003', ['apply', 'a1']),
            $this->approveNode('b1', 'u0004', ['apply']),
            new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd),
            $this->approveNode('final', 'u0010', ['apply', 'a1', 'a2', 'b1']),
        ], $this->chain(['apply', 'sync'], ['sync', 'a1'], ['a1', 'a2'], ['a2', 'sync_end'], ['sync', 'b1'], ['b1', 'sync_end'], ['sync_end', 'final'])];
    }

    /**
     * R3 = 横配置(chief(u0002)が指定、1〜3 人)の後に final(u0010)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    protected function r3(int $minAssignees = 1): array
    {
        return [[
            $this->applyNode(),
            $this->approveNode('chief', 'u0002', ['apply']),
            new RouteNodeInput('line', '順番の承認', WorkflowNodeKind::Serial, WorkflowTargetKind::Designated, designatorCode: 'chief', minAssignees: $minAssignees, maxAssignees: 3, sendBackCodes: ['apply', 'chief']),
            $this->approveNode('final', 'u0010', ['apply', 'chief']),
        ], $this->chain(['apply', 'chief'], ['chief', 'line'], ['line', 'final'])];
    }

    /**
     * 申請 → chief(u0002。指定する)→ family(横配置か縦配置。chief が指定、1〜3 人。縦配置は人数 2 で可決)→ final(u0010。family へ差戻せる)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    protected function placementRoute(WorkflowNodeKind $kind): array
    {
        $isParallel = $kind === WorkflowNodeKind::Parallel;

        return [[
            $this->applyNode(),
            $this->approveNode('chief', 'u0002', ['apply']),
            new RouteNodeInput('family', '配置', $kind, WorkflowTargetKind::Designated, designatorCode: 'chief', minAssignees: $isParallel ? 2 : 1, maxAssignees: 3, decisionRule: $isParallel ? WorkflowDecisionRule::Count : null, decisionValue: $isParallel ? 2 : null, sendBackCodes: ['apply', 'chief']),
            $this->approveNode('final', 'u0010', ['apply', 'chief', 'family']),
        ], $this->chain(['apply', 'chief'], ['chief', 'family'], ['family', 'final'])];
    }

    /**
     * 版をテストのフローの最後に足す(フローが無ければ作る)。
     *
     * @param  array{list<RouteNodeInput>, list<RouteEdgeInput>}  $route
     * @return int 版の id
     */
    protected function addVersion(array $route, string $startDate = self::ROUTE_START): int
    {
        if (! WorkflowFlow::query()->where('code', self::FLOW_CODE)->exists()) {
            $this->createFlow();
        }

        return app(AddRouteVersion::class)->execute(self::FLOW_CODE, CarbonImmutable::parse($startDate), $route[0], $route[1]);
    }

    /**
     * 版を写した未完了の案件(ノードはすべて到達していない)。担当組織 = 申請者の申請基準日の主所属。レコード = 業務モジュール project の行(値を渡す)。
     *
     * @param  array<string, mixed>  $values  項目のコード → 値
     */
    protected function caseOf(int $versionId, ?User $applicant = null, array $values = ['budget' => 100], string $baseDate = '2026-10-13'): WorkflowCase
    {
        $applicant ??= $this->seededUser('u0001');
        $flow = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole();
        $case = WorkflowCase::factory()->active()->createOne([
            'workflow_flow_id' => $flow->id, 'name' => '見本', 'user_id' => $applicant->id,
            'department_id' => app(CurrentDepartment::class)->departmentIdOn($applicant->id, null, CarbonImmutable::parse($baseDate)),
            'record_id' => $this->projectRecord($values)->id, 'base_date' => $baseDate, 'workflow_flow_version_id' => $versionId,
        ]);
        app(CaseRouteCopier::class)->copy($case, $versionId);

        return $case;
    }

    /**
     * 業務モジュール project のレコード(項目のコードで値を渡す)。
     *
     * @param  array<string, mixed>  $values
     */
    protected function projectRecord(array $values): Record
    {
        $module = RecordModule::query()->with('recordFields')->where('code', 'project')->sole();
        $fieldValues = [];

        foreach ($values as $code => $value) {
            $fieldValues[$module->recordFields->firstOrFail('code', $code)->id] = $value;
        }

        return Record::factory()->createOne(['record_module_id' => $module->id, 'field_values' => $fieldValues]);
    }

    protected function applyNode(): RouteNodeInput
    {
        return new RouteNodeInput('apply', '申請', WorkflowNodeKind::Apply, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::Role, Role::query()->where('code', 'general_user')->sole()->id)], ['ja' => '申請']);
    }

    /**
     * 対象者で指定(ユーザー 1 人)の承認のノード。
     *
     * @param  list<string>  $sendBackCodes
     */
    protected function approveNode(string $code, string $userCode, array $sendBackCodes = [], bool $prevents = false): RouteNodeInput
    {
        return new RouteNodeInput($code, $code, WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [$this->userCondition($userCode)], ['ja' => $code], preventsApplicantApproval: $prevents, sendBackCodes: $sendBackCodes);
    }

    /**
     * 名前と条件の無い辺(並び 0)。
     *
     * @param  array{string, string}  ...$pairs
     * @return list<RouteEdgeInput>
     */
    protected function chain(array ...$pairs): array
    {
        return array_values(array_map(fn (array $pair) => new RouteEdgeInput($pair[0], $pair[1]), $pairs));
    }

    protected function postId(string $code): int
    {
        return CompanyPost::query()->where('code', $code)->sole()->id;
    }
}
