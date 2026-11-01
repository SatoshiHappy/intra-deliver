<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowCompletionReason;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Authz\Role;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseConfirmation;
use App\Models\Workflow\WorkflowCaseConfirmTarget;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Master\Api\MasterNames;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseListQuery;
use Carbon\CarbonImmutable;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\ReadsCaseLists;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\TransactionalTestCase;

/**
 * 詳細の申請情報の期限と確認の面(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節、案件の操作/README.md の 1.4 節・1.5 節):
 * case.deadline は未完了だけが値を持ち、一覧の行と同じ値(現在のステップの全部が対象)。確認の面は確認の状態の 6 つの値を出し、確認が一度も
 * 開いていないステップは申請基準日の展開を表示だけに出す(確認対象者の行を書かない。完了の案件でも同じ)。can.confirm は判定の 1 か所の確認の前提。
 * 人は初期データの案件と代理に関わらない利用者(申請者 u0008、確認する人 u0006・u0007)。
 */
final class CaseDetailPanelsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use ReadsCaseLists;
    use ReadsCaseNotices;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 到達日・経過日数・期限日・期限超過は一覧の未処理の行と同じ値(c1 = 期限 3 日、到達 2026-10-13)。5 日後は超過。完了の案件は null。
     */
    public function test_the_deadline_equals_the_list_row_and_is_null_after_completion(): void
    {
        $this->addLine($this->listNode('c1', ['u0005'], deadlineDays: 3), $this->listNode('c2', ['u0013']));
        $case = $this->applyAs($this->seededUser(self::APPLICANT));
        $completed = $this->applyAs($this->seededUser(self::APPLICANT));
        $this->approve($completed, 'c1', 'u0005');
        $this->approve($completed, 'c2', 'u0013');

        $today = ['reachedOn' => '2026-10-13', 'elapsedDays' => 0, 'dueOn' => '2026-10-16', 'overdue' => false];
        $later = ['reachedOn' => '2026-10-13', 'elapsedDays' => 5, 'dueOn' => '2026-10-16', 'overdue' => true];

        $this->assertSame([$today, $today], $this->deadlinesOn('2026-10-13', $case));
        $this->assertSame([$later, $later], $this->deadlinesOn('2026-10-18', $case));
        $this->assertNull($this->show('u0005', $completed)['case']['deadline']);
    }

    /**
     * 確認の状態の 6 つ(再申請待ち・未確認・確認済・承認待ち・省略・—)と、確認が一度も開いていないステップ(c4)の展開。展開は行を書かない。
     */
    public function test_the_confirmation_panel_shows_each_status_and_expands_steps_not_opened(): void
    {
        [$active, $completed] = $this->confirmationCases();
        $u0006 = $this->seededUser('u0006')->id;
        $u0007 = $this->seededUser('u0007')->id;
        $rows = WorkflowCaseConfirmTarget::query()->count();
        $confirmedAt = CarbonImmutable::parse('2026-10-13 12:00:00')->toIso8601String();

        $panel = $this->panelOf('u0006', $active);

        $this->assertSame([
            ['申請', [[$u0006, true, 'reapply', null]]],
            ['c1', [[$u0006, true, 'unconfirmed', null], [$u0007, false, 'confirmed', $confirmedAt]]],
            ['c2', [[$u0006, true, 'waiting', null]]],
            ['c3', [[$u0006, true, 'skipped', null]]],
            ['c4', [[$u0006, true, 'waiting', null], [$u0007, false, 'waiting', null]]],
        ], $panel);
        $none = [[$u0006, true, 'none', null], [$u0007, false, 'none', null]];
        $this->assertSame([
            ['申請', $none], ['c1', [[$u0006, true, 'none', null]]], ['c2', $none], ['c3', $none], ['c4', $none],
        ], $this->panelOf('u0006', $completed));
        $this->assertSame($rows, WorkflowCaseConfirmTarget::query()->count());
    }

    /**
     * 確認する人の行は名前と所属(主所属の名前)を持つ。
     */
    public function test_a_confirmer_has_the_name_and_the_main_department(): void
    {
        [$active] = $this->confirmationCases();
        $u0006 = $this->seededUser('u0006');
        $names = app(MasterNames::class)->mainDepartmentNames([$u0006->id], $this->operationDay(), 'ja');
        $department = $names[$u0006->id];

        $person = $this->show('u0006', $active)['confirmations'][0]['people'][0];

        $actual = [$person['person']['id'], $person['person']['code'], $person['department']];
        $this->assertSame([$u0006->id, 'u0006', $department], $actual);
    }

    /**
     * can.confirm = 確認の前提(未完了で、自分が確認対象者の開いている確認がある)。未確認の u0006 は真、確認する人でない u0013 は偽、完了の後は偽。
     * 確認済の u0007 も開いている確認があるので真(何度でも確認できる = 案件の操作/README.md の 1.5 節、操作.md の 10.4 節)。
     */
    public function test_can_confirm_follows_the_precondition_of_the_confirmation(): void
    {
        [$active, $completed] = $this->confirmationCases();

        $this->assertSame(
            [true, true, false, false],
            [
                $this->show('u0006', $active)['can']['confirm'],
                $this->show('u0007', $active)['can']['confirm'],
                $this->show('u0013', $active)['can']['confirm'],
                $this->show('u0006', $completed)['can']['confirm'],
            ],
        );
    }

    public function test_a_case_without_confirming_steps_has_no_confirmation_panel(): void
    {
        $this->addLine($this->listNode('c1', ['u0005']));
        $case = $this->applyAs($this->seededUser(self::APPLICANT));

        $this->assertSame([], $this->show('u0005', $case)['confirmations']);
    }

    /**
     * 確認の面の見本: 全部のステップに確認する人(u0006・u0007)を持つ版を写した、未完了の案件と完了の案件。状態はノードと行を直接作る。
     *
     * @return array{WorkflowCase, WorkflowCase}
     */
    private function confirmationCases(): array
    {
        $group = $this->confirmGroup('u0006', 'u0007');
        $role = Role::query()->where('code', 'general_user')->sole()->id;
        $apply = new RouteNodeInput(
            'apply', '申請', WorkflowNodeKind::Apply, WorkflowTargetKind::Fixed,
            [new SelectionCondition(SelectionConditionKind::Role, $role)], ['ja' => '申請'],
            confirmSubjectGroupId: $group,
        );
        $node = fn (string $code) => $this->listNode($code, ['u0005'], confirmSubjectGroupId: $group);
        $nodes = array_map($node, ['c1', 'c2', 'c3', 'c4']);
        $edges = $this->chain(['apply', 'c1'], ['c1', 'c2'], ['c2', 'c3'], ['c3', 'c4']);
        $version = $this->addVersion([[$apply, ...$nodes], $edges]);
        $applicant = $this->seededUser(self::APPLICANT);
        [$active, $completed] = [$this->caseOf($version, $applicant), $this->caseOf($version, $applicant)];
        [$applyNode, $c1, $c2, $c3] = $this->nodesOf($active);
        $this->reach($applyNode, $applicant);
        $history = $this->process($c1, $this->seededUser('u0005'), WorkflowAction::Approve);
        $this->reach($c2, $this->seededUser('u0013'));
        $c3->update(['state' => WorkflowNodeState::Skipped]);
        $this->confirmTargets($applyNode, 'u0006');
        $this->confirmTargets($c1, 'u0006', 'u0007');
        $this->confirmTargets($c2, 'u0006');
        $this->confirmTargets($c3, 'u0006');
        WorkflowCaseConfirmation::factory()->create([
            'workflow_case_id' => $active->id, 'workflow_case_node_id' => $c1->id,
            'workflow_case_history_id' => $history->id, 'user_id' => $this->seededUser('u0007')->id,
        ]);
        $completed->update([
            'state' => WorkflowCaseState::Completed, 'completion_reason' => WorkflowCompletionReason::Rejected,
            'completed_at' => now(),
        ]);
        $this->confirmTargets($this->nodesOf($completed)[1], 'u0006');

        return [$active, $completed];
    }

    private function confirmTargets(WorkflowCaseNode $node, string ...$userCodes): void
    {
        foreach ($userCodes as $code) {
            WorkflowCaseConfirmTarget::factory()->create([
                'workflow_case_id' => $node->workflow_case_id, 'workflow_case_node_id' => $node->id,
                'user_id' => $this->seededUser($code)->id,
            ]);
        }
    }

    /**
     * 確認の面の [ステップ名, [人の id, 自分か, 状態, 確認日時]]。
     *
     * @return array<mixed>
     */
    private function panelOf(string $userCode, WorkflowCase $case): array
    {
        $person = fn (array $row) => [$row['person']['id'], $row['isMe'], $row['status'], $row['confirmedAt']];

        return array_map(
            fn (array $step) => [$step['stepName'], array_map($person, $step['people'])],
            $this->show($userCode, $case)['confirmations'],
        );
    }

    /**
     * その日の、詳細の期限と一覧の未処理の行の期限(処理担当者 u0005)。
     *
     * @return array{mixed, mixed}
     */
    private function deadlinesOn(string $date, WorkflowCase $case): array
    {
        $context = $this->contextOf($this->seededUser('u0005'), date: $date);
        $list = app(CaseListQuery::class)->list($context, 'pending', null, null, null);
        $rows = array_column($list['cases']['data'], null, 'id');

        $detail = app(CaseDetailQuery::class)->show($context, $case->id);

        return [$detail['case']['deadline'], $rows[$case->id]['deadline']];
    }

    /**
     * @return array<string, mixed>
     */
    private function show(string $userCode, WorkflowCase $case): array
    {
        return app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser($userCode)), $case->id);
    }
}
