<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\SendBackCase;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseAssignment;
use App\Queries\Workflow\CurrentAssignments;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsRoutePlans;
use Tests\Support\TransactionalTestCase;

/**
 * 処理の時の指定(docs の 設計/ワークフロー/ルートの深さ/案件の操作/処理対象者.md 5.2・5.3、同時実行と検査.md 13 節・14 節の「指定」)。R1 の課長(指定するノード)が
 * 合議(2〜5 人、防止オン)と責任者(1 人、防止オン)を指定する。5.2 の検証の 5 つはどれも 422 で何も書かない。期待の文言は 13 節の表から書いた。
 */
final class CaseAssignmentsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsRoutePlans;

    /**
     * 規則 1: 入力の Y の集まりが X の指定するノードの集まりと同じ(欠け・余りとも 422 assignments)。
     */
    public function test_missing_or_extra_designated_nodes_are_rejected(): void
    {
        $case = $this->applied();
        $ids = $this->labelIds($case);
        $other = $this->applied();

        $this->assertRejected($case, ['council' => ['u0004', 'u0005']], ['assignments' => '指定するステップの処理担当者を指定してください']);
        $this->assertRejected($case, [], ['assignments' => '指定するステップの処理担当者を指定してください']);
        $this->assertRejectedById($case, [$ids['council'] => $this->userIds('u0004', 'u0005'), $ids['owner'] => $this->userIds('u0010'), $ids['manager'] => $this->userIds('u0003')], ['assignments' => '指定するステップの処理担当者を指定してください']);
        $this->assertRejectedById($case, [$ids['council'] => $this->userIds('u0004', 'u0005'), $this->labelIds($other)['owner'] => $this->userIds('u0010')], ['assignments' => '指定するステップの処理担当者を指定してください']);
    }

    /**
     * 規則 2〜5(Y ごとに 422 assignments.<Y の id>): 人数の外(最小 = 最大の文言を含む)、重複、存在しない・申請基準日に有効でない人、申請者承認防止の Y に申請者。
     */
    public function test_each_designated_node_is_checked_by_count_duplicates_effectiveness_and_the_applicant(): void
    {
        $case = $this->applied();
        $ids = $this->labelIds($case);
        $council = "assignments.{$ids['council']}";
        $owner = "assignments.{$ids['owner']}";
        $missing = (int) User::query()->max('id') + 1000;

        $this->assertRejected($case, ['council' => ['u0004'], 'owner' => ['u0010', 'u0011']], [$council => '関係部署の合議の処理担当者は 2 人から 5 人まで指定してください', $owner => '責任者の処理担当者は 1 人を指定してください']);
        $this->assertRejected($case, ['council' => ['u0004', 'u0005', 'u0006', 'u0007', 'u0008', 'u0009'], 'owner' => ['u0010']], [$council => '関係部署の合議の処理担当者は 2 人から 5 人まで指定してください']);
        $this->assertRejected($case, ['council' => ['u0004', 'u0004'], 'owner' => ['u0010']], [$council => '同じユーザーを重複して指定できません']);
        $this->assertRejected($case, ['council' => ['u0004', 'u0014'], 'owner' => ['u0015']], [$council => '申請基準日に有効でないユーザーは指定できません', $owner => '申請基準日に有効でないユーザーは指定できません']);
        $this->assertRejectedById($case, [$ids['council'] => [...$this->userIds('u0004'), $missing], $ids['owner'] => $this->userIds('u0010')], [$council => '申請基準日に有効でないユーザーは指定できません']);
        $this->assertRejected($case, ['council' => ['u0004', 'u0001'], 'owner' => ['u0001']], [$council => '申請者本人は、このステップの承認者に選択できません', $owner => '申請者本人は、このステップの承認者に選択できません']);
    }

    /**
     * 人の有効性は規則 2・3 を通った Y の人だけをまとめて 1 回で引く: どの Y も人数の外なら users の問い合わせが出ず(大きな入力を SQL に渡さない)、
     * 通った Y があれば 1 回だけ出る。
     */
    public function test_effectiveness_is_looked_up_once_and_only_for_designations_that_passed_the_count_checks(): void
    {
        $case = $this->applied();
        $lookups = fn (array $assignments) => count(array_filter($this->recordedSql(fn () => $this->validationErrors(fn () => $this->approveAs($case, $assignments))), fn (string $sql) => preg_match('/from "users" where "users"\."id" in \(/', $sql) === 1));

        $this->assertSame(0, $lookups(['council' => ['u0004', 'u0005', 'u0006', 'u0007', 'u0008', 'u0009'], 'owner' => ['u0010', 'u0011']]));
        $this->assertSame(1, $lookups(['council' => ['u0004', 'u0014'], 'owner' => ['u0015']]));
    }

    /**
     * 申請基準日で有効を見る: 申請基準日 2026-12-01 の案件では、今日(2026-10-13)はまだ入社していない u0015 を指定できる。
     */
    public function test_effectiveness_is_judged_on_the_base_date(): void
    {
        $result = app(ApplyCase::class)->execute($this->contextOf($this->seededUser('u0001')), self::FLOW_CODE, $this->projectInput(['budget' => 500]), '2026-12-01');
        $case = WorkflowCase::query()->findOrFail($result->caseId);

        $this->approve($case, ['council' => ['u0004', 'u0015'], 'owner' => ['u0010']]);

        $this->assertSame($this->userIds('u0004', 'u0015'), app(CurrentAssignments::class)->of($case->id)[$this->labelIds($case)['council']]);
    }

    /**
     * 初回の指定と、差戻しで指定するノードへ戻って人数・人を変えた再指定のどちらも、その承認の計画で到達する人が入力の人になる(今の指定 = 最新の X の承認の指定)。
     * 差戻しは分岐の前へ戻すので、分岐開始と部長も到達していないに戻り、再び分岐を評価する。
     */
    public function test_a_redesignation_after_a_send_back_is_used_for_the_next_reach(): void
    {
        $case = $this->applied();
        $this->approve($case, ['council' => ['u0004', 'u0005', 'u0009'], 'owner' => ['u0010']]);
        $this->operatedApprove($case, 'manager', 'u0003');
        $this->assertSame(['running', 'waiting[u0004]', 'waiting[u0005]', 'waiting[u0009]'], $this->family($case, 'council'));

        app(SendBackCase::class)->execute($this->contextOf($this->seededUser('u0004')), $case->id, $this->revisionOf($case), $this->labelIds($case)['council.1'], $this->labelIds($case)['section_chief'], null);
        $this->assertRouteInvariants($case);
        $this->assertSame(['apply' => 'done', 'section_chief' => 'waiting[u0002]', 'budget' => 'unreached', 'manager' => 'unreached', 'budget_end' => 'unreached', 'council' => 'unreached', 'council.1' => 'unreached', 'council.2' => 'unreached', 'council.3' => 'unreached', 'owner' => 'unreached'], $this->shape($case));

        $this->approve($case, ['council' => ['u0006', 'u0007'], 'owner' => ['u0011']]);
        $this->operatedApprove($case, 'manager', 'u0003');

        $this->assertSame(['running', 'waiting[u0006]', 'waiting[u0007]', 'skipped'], $this->family($case, 'council'));
        $this->assertSame(['council' => $this->userIds('u0006', 'u0007'), 'owner' => $this->userIds('u0011')], $this->byLabel($case, app(CurrentAssignments::class)->of($case->id)));
        $this->assertSame(7, WorkflowCaseAssignment::query()->where('workflow_case_id', $case->id)->count());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->addVersion($this->r1());
    }

    private function applied(): WorkflowCase
    {
        $result = app(ApplyCase::class)->execute($this->contextOf($this->seededUser('u0001')), self::FLOW_CODE, $this->projectInput(['budget' => 500]), $this->operationDay()->toDateString());

        return WorkflowCase::query()->findOrFail($result->caseId);
    }

    /**
     * 課長(u0002)が承認する。
     *
     * @param  array<string, list<string>>  $assignments  指定されるノードの名札 → 人のコード
     */
    private function approve(WorkflowCase $case, array $assignments): void
    {
        $ids = $this->labelIds($case);
        $byNode = [];

        foreach ($assignments as $label => $codes) {
            $byNode[$ids[$label]] = $this->userIds(...$codes);
        }

        app(ApproveCase::class)->execute($this->contextOf($this->seededUser('u0002')), $case->id, $this->revisionOf($case), $ids['section_chief'], null, $byNode);
        $this->assertRouteInvariants($case);
    }

    private function operatedApprove(WorkflowCase $case, string $label, string $userCode): void
    {
        app(ApproveCase::class)->execute($this->contextOf($this->seededUser($userCode)), $case->id, $this->revisionOf($case), $this->labelIds($case)[$label], null);
        $this->assertRouteInvariants($case);
    }

    /**
     * 課長(u0002)が名札の指定で承認する(結果は呼び手が見る)。
     *
     * @param  array<string, list<string>>  $assignments
     */
    private function approveAs(WorkflowCase $case, array $assignments): void
    {
        $ids = $this->labelIds($case);
        $byNode = [];

        foreach ($assignments as $label => $codes) {
            $byNode[$ids[$label]] = $this->userIds(...$codes);
        }

        app(ApproveCase::class)->execute($this->contextOf($this->seededUser('u0002')), $case->id, $this->revisionOf($case), $ids['section_chief'], null, $byNode);
    }

    /**
     * @param  array<string, list<string>>  $assignments
     * @param  array<string, string>  $expected
     */
    private function assertRejected(WorkflowCase $case, array $assignments, array $expected): void
    {
        $ids = $this->labelIds($case);
        $byNode = [];

        foreach ($assignments as $label => $codes) {
            $byNode[$ids[$label]] = $this->userIds(...$codes);
        }

        $this->assertRejectedById($case, $byNode, $expected);
    }

    /**
     * @param  array<int, list<int>>  $assignments
     * @param  array<string, string>  $expected
     */
    private function assertRejectedById(WorkflowCase $case, array $assignments, array $expected): void
    {
        $before = [$this->shape($case), $this->workflowCounts(), WorkflowCaseAssignment::query()->count(), $this->revisionOf($case)];

        $errors = $this->validationErrors(fn () => app(ApproveCase::class)->execute($this->contextOf($this->seededUser('u0002')), $case->id, $this->revisionOf($case), $this->labelIds($case)['section_chief'], null, $assignments));

        $this->assertSame($expected, $errors);
        $this->assertSame($before, [$this->shape($case), $this->workflowCounts(), WorkflowCaseAssignment::query()->count(), $this->revisionOf($case)]);
    }

    /**
     * @return list<int>
     */
    private function userIds(string ...$codes): array
    {
        return array_values(array_map(fn (string $code) => $this->seededUser($code)->id, $codes));
    }

    /**
     * @return list<string>
     */
    private function family(WorkflowCase $case, string $parent): array
    {
        return array_values(array_filter($this->shape($case), fn (string $label) => $label === $parent || str_starts_with($label, "{$parent}."), ARRAY_FILTER_USE_KEY));
    }

    /**
     * @param  array<int, list<int>>  $byNodeId
     * @return array<string, list<int>>
     */
    private function byLabel(WorkflowCase $case, array $byNodeId): array
    {
        $labels = array_flip($this->labelIds($case));
        $result = [];

        foreach ($byNodeId as $nodeId => $userIds) {
            $result[$labels[$nodeId]] = $userIds;
        }

        return $result;
    }
}
