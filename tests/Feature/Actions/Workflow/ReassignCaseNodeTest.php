<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 指定の変更(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.6 節と 15.2 節の行・同時実行と検査.md の 13 節、
 * 設計/ワークフロー/案件の運用/データモデル/表.md の 3.5 節):
 * X の承認で指定した後に指定の変更で別の人にすると、Y の到達でその人に展開する。人数は定義の最小〜最大で、最小 0 の Y だけ 0 人にでき、
 * 到達で省かれる。前の指定の行は残る。規則 (1)〜(5) はどれも 422 user_ids、前提(Y が到達した後・X が処理済みでない・Y がほかのノードの
 * 指定するノード)は 422 case で、何も変わらない。
 * ルートの見本 R1: 課長(u0002)が合議(縦配置、2〜5 人)と責任者(動的承認、1〜1 人)を指定する(どちらも申請者承認防止がオン)。管理者 = u0004。
 */
final class ReassignCaseNodeTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->addVersion($this->r1());
    }

    /**
     * 責任者の指定を u0007 → u0006 に変えると、合議の可決の後の責任者の到達で u0006 に展開する。履歴 = 指定の変更(行のノード = 責任者、処理者 = 管理者、
     * 履歴の人なし)、改訂番号 +1。前の指定の行(課長の承認の行)も残る。
     */
    public function test_reassigning_the_owner_changes_who_it_reaches(): void
    {
        $case = $this->designatedCase(100);
        $revision = $this->revisionOf($case);

        $result = $this->reassignOn($case, 'owner', ['u0006']);
        $written = [$this->revisionOf($case), $this->latestRow($case), $result->targetAdditions];
        $this->approve($case, 'council.1', 'u0005');
        $this->approve($case, 'council.2', 'u0009');

        $this->assertSame('waiting[u0006]', $this->shape($case)['owner']);
        $this->assertSame([$revision + 1, ['reassign', self::MANAGER, null, [], []], []], $written);
        $rows = array_values(array_filter($this->assignmentRows($case), fn (array $row) => $row[1] === 'owner'));
        $this->assertSame(
            [['owner', 1, 'u0007'], ['owner', 1, 'u0006']],
            array_map(fn (array $row) => array_slice($row, 1), $rows),
        );
        $this->assertSame($result->historyId, $rows[1][0]);
    }

    /**
     * 最小 0 の責任者(R1 の責任者を 0〜1 人にした版)を 0 人にすると、合議の可決の後に責任者を省いて完了する(動的承認の削除)。
     * 指定の行は課長の承認の行のものだけが残る(0 人は行を書かない)。
     */
    public function test_reassigning_no_one_skips_the_owner(): void
    {
        $this->addVersion($this->r1WithOptionalOwner(), '2026-02-01');
        $case = $this->designatedCase(100);

        $this->reassignOn($case, 'owner', []);
        $this->approve($case, 'council.1', 'u0005');
        $this->approve($case, 'council.2', 'u0009');

        $this->assertSame('skipped', $this->shape($case)['owner']);
        $this->assertSame('completed', WorkflowCase::query()->findOrFail($case->id)->state->value);
        $owners = array_filter($this->assignmentRows($case), fn (array $row) => $row[1] === 'owner');
        $rows = array_values(array_map(fn (array $row) => array_slice($row, 1), $owners));
        $this->assertSame([['owner', 1, 'u0007']], $rows);
    }

    /**
     * 規則 (1)〜(5): 人数(合議 2〜5・責任者 1〜1 に 2 人と 0 人)・申請基準日に有効でない(退職 u0014)・重複・申請者と今日の申請者の代理先・自分(管理者)。
     * 部長が承認待ちで合議と責任者が到達していない案件(予算 300)。どれも 422 user_ids で何も変わらない。
     */
    public function test_the_rules_for_the_people(): void
    {
        $this->addProxy($this->seededUser('u0001'), $this->seededUser('u0008'));
        $case = $this->designatedCase(300);
        $before = $this->snapshot($case);

        $this->assertSame([
            ['user_ids' => '関係部署の合議の処理担当者は 2 人から 5 人まで指定してください'],
            ['user_ids' => '責任者の処理担当者は 1 人を指定してください'],
            ['user_ids' => '責任者の処理担当者は 1 人を指定してください'],
            ['user_ids' => '申請基準日に有効でないユーザーは指定できません'],
            ['user_ids' => '同じユーザーを重複して指定できません'],
            ['user_ids' => '申請者本人は、このステップの処理担当者に指定できません'],
            ['user_ids' => '申請者の代理先は、このステップの処理担当者に指定できません'],
            ['user_ids' => '自分自身は処理担当者に指定できません'],
        ], [
            $this->attemptReassign($case, 'council', ['u0006']),
            $this->attemptReassign($case, 'owner', ['u0006', 'u0007']),
            $this->attemptReassign($case, 'owner', []),
            $this->attemptReassign($case, 'council', ['u0006', 'u0014']),
            $this->attemptReassign($case, 'council', ['u0006', 'u0006']),
            $this->attemptReassign($case, 'council', ['u0006', 'u0001']),
            $this->attemptReassign($case, 'council', ['u0006', 'u0008']),
            $this->attemptReassign($case, 'council', ['u0006', self::MANAGER]),
        ]);
        $this->assertSame($before, $this->snapshot($case));
        $this->reassignOn($case, 'council', ['u0006', 'u0007']);
        $this->assertSame('unreached', $this->shape($case)['council']);
    }

    /**
     * 前提: Y が到達した後(合議)・X(課長)が処理済みでない・Y がほかのノードの指定するノード → 422 case で何も変わらない。
     */
    public function test_the_preconditions_of_the_node(): void
    {
        $reached = $this->designatedCase(100);
        [$waiting] = $this->applyOnRoute(['budget' => 100]);
        $chained = $this->chainedCase();
        $before = [$this->snapshot($reached), $this->snapshot($waiting), $this->snapshot($chained)];

        $errors = [
            $this->attemptOf(fn () => $this->reassignOn($reached, 'council', ['u0006', 'u0007'])),
            $this->attemptOf(fn () => $this->reassignOn($waiting, 'owner', ['u0006'])),
            $this->attemptOf(fn () => $this->reassignOn($chained, 'y', ['u0006'])),
        ];

        $this->assertSame(array_fill(0, 3, ['case' => 'この申請は今この操作をできません']), $errors);
        $this->assertSame($before, [$this->snapshot($reached), $this->snapshot($waiting), $this->snapshot($chained)]);
    }

    /**
     * 指定の変更を savepoint の中で行う('success' か 422 のキー → 文言)。
     *
     * @param  list<string>  $codes
     * @return string|array<string, string>
     */
    private function attemptReassign(WorkflowCase $case, string $label, array $codes): string|array
    {
        return $this->attemptOf(fn () => $this->reassignOn($case, $label, $codes));
    }

    /**
     * R1 を予算 $budget で申請し、課長が合議 u0005・u0009・u0011 と責任者 u0007 を指定して承認した案件。
     */
    private function designatedCase(int $budget): WorkflowCase
    {
        [$case] = $this->applyOnRoute(['budget' => $budget]);
        $designations = ['council' => ['u0005', 'u0009', 'u0011'], 'owner' => ['u0007']];
        $this->approve($case, 'section_chief', 'u0002', $designations);

        return $case;
    }

    /**
     * 申請 → x(u0002。y を指定)→ m(u0010)→ y(x が指定。z を指定)→ z(y が指定)の版で、x が y に u0003 を指定して承認した案件(m が承認待ち)。
     */
    private function chainedCase(): WorkflowCase
    {
        $designated = fn (string $code, string $designator) => new RouteNodeInput(
            $code,
            $code,
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Designated,
            designatorCode: $designator,
            minAssignees: 1,
            maxAssignees: 1,
            sendBackCodes: ['apply'],
        );
        $nodes = [
            $this->applyNode(),
            $this->approveNode('x', 'u0002', ['apply']),
            $this->approveNode('m', 'u0010', ['apply']),
            $designated('y', 'x'),
            $designated('z', 'y'),
        ];
        $this->addVersion([$nodes, $this->chain(['apply', 'x'], ['x', 'm'], ['m', 'y'], ['y', 'z'])], '2026-02-01');
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'x', 'u0002', ['y' => ['u0003']]);

        return $case;
    }

    /**
     * R1 の責任者を最小 0(0〜1 人)にした版。責任者は最後のノードで、後ろに前処理者を起点にするノードが無い。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function r1WithOptionalOwner(): array
    {
        [$nodes, $edges] = $this->r1();
        $owner = new RouteNodeInput(
            'owner',
            '責任者',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Designated,
            designatorCode: 'section_chief',
            minAssignees: 0,
            maxAssignees: 1,
            preventsApplicantApproval: true,
            sendBackCodes: ['apply', 'section_chief'],
        );
        $nodes = array_map(fn (RouteNodeInput $node) => $node->code === 'owner' ? $owner : $node, $nodes);

        return [$nodes, $edges];
    }

    /**
     * @return array{array<string, string>, array<string, int>, int}
     */
    private function snapshot(WorkflowCase $case): array
    {
        return [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
    }
}
