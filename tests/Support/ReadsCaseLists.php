<?php

namespace Tests\Support;

use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseListQuery;
use App\Queries\Workflow\CaseRows;
use Carbon\CarbonImmutable;

/**
 * 案件一覧のタブ・検索・行の項目のテストの手助け(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.1 節・2.2 節・6 節)。案件は本物の業務処理で作る。
 * 人は初期データのうち、初期データの案件・代理・参照者に関わらない利用者(申請者 u0008、処理する人 u0005・u0006・u0007・u0013)を使い、
 * タブの行を名指しで比べる。判定の日 = 操作の基準日({@see RunsWorkflowOperations::operationDay()})。
 *
 * @phpstan-import-type CaseList from CaseListQuery
 * @phpstan-import-type CaseRow from CaseRows
 */
trait ReadsCaseLists
{
    use OperatesHolds;
    use RegistersProxies;
    use RunsBulkOperations;

    /** 申請者 */
    protected const APPLICANT = 'u0008';

    /**
     * 案件一覧(クエリは画面と同じ値を渡す)。
     *
     * @return CaseList
     */
    protected function listOf(
        string $userCode,
        mixed $tab,
        mixed $scope = null,
        mixed $q = null,
        mixed $page = null,
    ): array {
        $context = $this->contextOf($this->seededUser($userCode));

        return app(CaseListQuery::class)->list($context, $tab, $scope, $page, $q);
    }

    /**
     * タブの行の案件の id(並びの順)。
     *
     * @return list<int>
     */
    protected function listedIds(string $userCode, mixed $tab, mixed $scope = null, mixed $q = null): array
    {
        return array_column($this->listOf($userCode, $tab, $scope, $q)['cases']['data'], 'id');
    }

    /**
     * タブの中のその案件の行(無ければテストを失敗させる)。
     *
     * @return CaseRow
     */
    protected function rowOf(string $userCode, string $tab, WorkflowCase $case): array
    {
        $rows = array_column($this->listOf($userCode, $tab)['cases']['data'], null, 'id');
        $this->assertArrayHasKey($case->id, $rows, "{$userCode} の {$tab} に案件 {$case->id} が無い");

        return $rows[$case->id];
    }

    /**
     * 時刻(UTC)に時計を合わせて申請する(到達日時・処理日時はその時刻)。
     */
    protected function applyAt(string $time, ?string $name = null): WorkflowCase
    {
        $this->travelTo(CarbonImmutable::parse($time));

        return $this->applyAs($this->seededUser(self::APPLICANT), input: $this->projectInput(name: $name));
    }

    /**
     * 対象者で指定(ユーザー 1 人以上。どれか 1 人で進む)の承認のノード。
     *
     * @param  list<string>  $userCodes
     */
    protected function listNode(
        string $code,
        array $userCodes,
        ?int $deadlineDays = null,
        ?int $confirmSubjectGroupId = null,
    ): RouteNodeInput {
        return new RouteNodeInput(
            $code,
            $code,
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            array_map(fn (string $userCode) => $this->userCondition($userCode), $userCodes),
            ['ja' => $code],
            sendBackCodes: ['apply'],
            deadlineDays: $deadlineDays,
            confirmSubjectGroupId: $confirmSubjectGroupId,
        );
    }

    /**
     * 申請からノードを一本道に並べた版を足す。
     */
    protected function addLine(RouteNodeInput ...$nodes): void
    {
        $nodes = array_values($nodes);
        $codes = ['apply', ...array_map(fn (RouteNodeInput $node) => $node->code, $nodes)];
        $edgeOf = fn (int $index) => new RouteEdgeInput($codes[$index], $codes[$index + 1]);
        $edges = array_map($edgeOf, range(0, count($nodes) - 1));
        $this->addVersion([[$this->applyNode(), ...$nodes], $edges]);
    }

    /**
     * 同期で 2 つのノードを並べた版(申請 → 同期(left・right)→ 同期終了 → final(u0013))を足す。
     */
    protected function addPair(RouteNodeInput $left, RouteNodeInput $right): void
    {
        $this->addVersion([[
            $this->applyNode(),
            new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'),
            $left,
            $right,
            new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd),
            $this->listNode('final', ['u0013']),
        ], $this->chain(
            ['apply', 'sync'],
            ['sync', $left->code],
            ['sync', $right->code],
            [$left->code, 'sync_end'],
            [$right->code, 'sync_end'],
            ['sync_end', 'final'],
        )]);
    }
}
