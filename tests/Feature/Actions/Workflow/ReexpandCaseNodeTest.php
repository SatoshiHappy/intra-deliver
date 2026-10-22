<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Master\CreateDepartmentMembership;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ReexpandCaseNode;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowRelativeBase;
use App\Enums\Workflow\WorkflowRelativeScope;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\DepartmentMembership;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowCaseNodeTarget;
use App\Support\Period;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\RegistersProxies;
use Tests\Support\TransactionalTestCase;

/**
 * 再展開(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.4 節と 15.1 節の 1、同時実行と検査.md の 14.2 節): 写した指定を申請基準日に
 * 展開し直した人に戻る(振替・処理対象者の変更の前の人)。前処理者を起点にする指定は記録した前処理者と起点の組織で展開し、管理者の選んだ組織は
 * 混ざらない。0 人・申請者と申請者の代理先だけ・ほかの子の人と重なる → 422 case で何も変わらない。管理者 = システム管理者 u0004。
 * 前処理者を起点にする rel(上位組織の全員)= 東京営業課の上位の営業部(u0003・u0013)、人事部の上位の管理本部(u0007)。
 */
final class ReexpandCaseNodeTest extends TransactionalTestCase
{
    use OperatesAsCaseManager;
    use RegistersProxies;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 振替(u0002 → u0008)と処理対象者の変更(→ u0006)の後の再展開で、写した指定の u0002 に戻る(履歴 = 再展開、履歴の人 = 展開し直した人)。
     */
    public function test_reexpanding_returns_to_the_people_of_the_copy(): void
    {
        $nodes = [$this->applyNode(), $this->approveNode('a1', 'u0002', ['apply'])];
        $this->addVersion([$nodes, $this->chain(['apply', 'a1'])]);
        [$case] = $this->applyOnRoute([]);
        $reachedAt = $this->reachedAtOf($case, 'a1');
        $this->transferOn($case, 'a1', 'u0002', $this->seededUser('u0008')->id);
        $this->retargetOn($case, 'a1', ['u0006']);

        $this->reexpandOn($case, 'a1');

        $this->assertSame(['waiting[u0002]', $reachedAt], [$this->shape($case)['a1'], $this->reachedAtOf($case, 'a1')]);
        $this->assertSame(['reexpand', self::MANAGER, null, [], ['u0002']], $this->latestRow($case));
    }

    /**
     * 前処理者を起点にする指定は、記録した前処理者(u0002)と起点の組織で展開する。u0002 が兼務の人事部を選んで承認した案件は、再展開も人事部から
     * (管理者が自分の組織を選んでいても混ざらない)。主所属で承認した案件は東京営業課から。
     */
    public function test_a_previous_based_node_expands_from_the_recorded_origin(): void
    {
        $this->addPreviousRoute();
        $humanResources = $this->joinHumanResources('u0002');
        [$byMain] = $this->applyOnRoute([]);
        [$bySecondary] = $this->applyOnRoute([]);
        $this->approve($byMain, 'a1', 'u0002');
        $this->approveIn($bySecondary, $humanResources);
        $shapes = [$this->shape($byMain)['rel'], $this->shape($bySecondary)['rel']];

        foreach ([$byMain, $bySecondary] as $case) {
            $this->retargetOn($case, 'rel', ['u0006']);
            $manager = $this->contextOf($this->seededUser(self::MANAGER), $this->departmentIdOf('planning'));
            app(ReexpandCaseNode::class)
                ->execute($manager, $case->id, $this->revisionOf($case), $this->labelIds($case)['rel'], null);
        }

        $this->assertSame(['waiting[u0003,u0013]', 'waiting[u0007]'], $shapes);
        $this->assertSame($shapes, [$this->shape($byMain)['rel'], $this->shape($bySecondary)['rel']]);
    }

    /**
     * 記録が無いとき: 前処理者が無ければ申請者と担当組織(東京営業課)から、起点の組織だけが無ければ前処理者 u0002 の主所属(東京営業課)から展開する。
     */
    public function test_a_missing_record_falls_back_to_the_applicant_or_the_main_department(): void
    {
        $this->addPreviousRoute();
        $humanResources = $this->joinHumanResources('u0002');
        [$withoutUser] = $this->applyOnRoute([]);
        [$withoutDepartment] = $this->applyOnRoute([]);

        $cleared = [[$withoutUser, 'previous_user_id'], [$withoutDepartment, 'previous_department_id']];

        foreach ($cleared as [$case, $column]) {
            $this->approveIn($case, $humanResources);
            WorkflowCaseNode::query()->whereKey($this->labelIds($case)['rel'])->update([$column => null]);
            $this->reexpandOn($case, 'rel');
        }

        $this->assertSame(
            ['waiting[u0003,u0013]', 'waiting[u0003,u0013]'],
            [$this->shape($withoutUser)['rel'], $this->shape($withoutDepartment)['rel']],
        );
    }

    /**
     * 展開し直した人が 0 人(起点の組織が頂点で上位組織が無い)→ 422 case(前のノードからの到達と同じ文言)で何も変わらない。
     */
    public function test_no_one_on_reexpanding_is_rejected(): void
    {
        $this->addPreviousRoute();
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'a1', 'u0002');
        $top = $this->departmentIdOf('deliver');
        WorkflowCaseNode::query()->whereKey($this->labelIds($case)['rel'])->update(['previous_department_id' => $top]);
        $before = $this->snapshot($case);

        $error = $this->attemptOf(fn () => $this->reexpandOn($case, 'rel'));

        $this->assertSame(['case' => 'relの処理対象者がいないため、この操作はできません'], $error);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 申請者承認防止がオンのノード(申請者の組織の全員 = u0001・u0002): ほかの人がいれば申請者も処理対象者に残る。u0002 が今日の申請者の代理先なら、
     * 申請者と申請者の代理先だけ → 422 case で何も変わらない。
     */
    public function test_a_preventing_node_keeps_the_applicant_unless_only_applicants_remain(): void
    {
        $guarded = new RouteNodeInput(
            'guarded',
            'guarded',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Relative,
            relativeBase: WorkflowRelativeBase::Applicant,
            relativeScope: WorkflowRelativeScope::Own,
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $this->addVersion([[$this->applyNode(), $guarded], $this->chain(['apply', 'guarded'])]);
        [$case] = $this->applyOnRoute([]);
        $this->retargetOn($case, 'guarded', ['u0006']);

        $this->reexpandOn($case, 'guarded');
        $kept = $this->shape($case)['guarded'];
        $this->addProxy($this->seededUser('u0001'), $this->seededUser('u0002'));
        $before = $this->snapshot($case);
        $error = $this->attemptOf(fn () => $this->reexpandOn($case, 'guarded'));

        $this->assertSame('waiting[u0001,u0002]', $kept);
        $message = 'guardedの処理担当者が申請者本人と申請者の代理先だけのため、この操作はできません。'
            .'このステップには、申請者承認防止が設定されています。';
        $this->assertSame(['case' => $message], $error);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 展開の行は今の指定のその番号の人(縦配置の子 1 = u0005)。その人がほかの子の今の人と重なると 422 case で何も変わらない
     * (規則を通らずに重なった行の形を、テストで行を書いて作る)。
     */
    public function test_a_council_child_reexpands_to_its_designated_person_unless_it_overlaps(): void
    {
        $this->addVersion($this->placementRoute(WorkflowNodeKind::Parallel));
        [$case] = $this->applyOnRoute([]);
        $this->approve($case, 'chief', 'u0002', ['family' => ['u0005', 'u0009', 'u0011']]);
        $this->retargetOn($case, 'family.1', ['u0006']);
        $this->reexpandOn($case, 'family.1');
        $returned = $this->shape($case)['family.1'];
        $this->retargetOn($case, 'family.1', ['u0006']);
        WorkflowCaseNodeTarget::query()->where('workflow_case_node_id', $this->labelIds($case)['family.2'])
            ->update(['user_id' => $this->seededUser('u0005')->id]);
        $before = $this->snapshot($case);

        $error = $this->attemptOf(fn () => $this->reexpandOn($case, 'family.1'));

        $this->assertSame('waiting[u0005]', $returned);
        $this->assertSame(['case' => 'このステップのほかの承認者と同じユーザーは指定できません'], $error);
        $this->assertSame($before, $this->snapshot($case));
    }

    /**
     * 申請 → a1(u0002)→ rel(前処理者を起点に上位組織の全員)の版。
     */
    private function addPreviousRoute(): void
    {
        $rel = new RouteNodeInput(
            'rel',
            'rel',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Relative,
            relativeBase: WorkflowRelativeBase::Previous,
            relativeScope: WorkflowRelativeScope::Parent,
            sendBackCodes: ['apply'],
        );
        $nodes = [$this->applyNode(), $this->approveNode('a1', 'u0002', ['apply']), $rel];
        $this->addVersion([$nodes, $this->chain(['apply', 'a1'], ['a1', 'rel'])]);
    }

    /**
     * u0002 が選んだ組織で a1 を承認する。
     */
    private function approveIn(WorkflowCase $case, int $departmentId): void
    {
        $context = $this->contextOf($this->seededUser('u0002'), $departmentId);
        $nodeId = $this->labelIds($case)['a1'];
        app(ApproveCase::class)->execute($context, $case->id, $this->revisionOf($case), $nodeId, null);
    }

    /**
     * 人事部の兼務の所属を足す(期間は初期データの兼務 = u0009 の人事部と同じ)。
     *
     * @return int 人事部の id
     */
    private function joinHumanResources(string $userCode): int
    {
        $departmentId = $this->departmentIdOf('human-resources');
        $first = DepartmentMembership::query()->where('department_id', $departmentId)->oldest('start_date')
            ->firstOrFail();
        $period = new Period($first->start_date->toImmutable(), null);
        $userId = $this->seededUser($userCode)->id;
        app(CreateDepartmentMembership::class)->execute($userId, $departmentId, $period, false, 99);

        return $departmentId;
    }

    /**
     * @return array{array<string, string>, array<string, int>, int}
     */
    private function snapshot(WorkflowCase $case): array
    {
        return [$this->shape($case), $this->workflowCounts(), $this->revisionOf($case)];
    }
}
