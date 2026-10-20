<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowProxy;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\CaseListQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;
use Throwable;

/**
 * 現在のノードが集合になった後の読み取り(docs の 設計/ワークフロー/ルートの深さ/認可と一覧.md 1〜3 節・7 節の読み取りの Feature テスト)。期待は 7 節の箇条から書いた。
 * ホームの節(H1〜H3)の期待は区切り 3-3。
 */
final class CaseReadingOnRoutesTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    /**
     * 現在のノードが 2 つ(同期の枝)の案件は、どちらの処理対象者の未処理にも出る。一覧の行の現在のノードは名前を「、」で並べる。
     */
    public function test_a_case_with_two_current_nodes_is_pending_for_both_targets_and_the_row_lists_both_names(): void
    {
        $this->addVersion($this->r2());
        $case = $this->applyOnRoute([])[0];

        foreach (['u0002', 'u0004'] as $code) {
            $rows = app(CaseListQuery::class)->list($this->contextOf($this->seededUser($code)), 'pending', null, null)['cases']['data'];

            $mine = array_values(array_filter($rows, fn (array $row) => $row['id'] === $case->id));

            $this->assertSame([['state' => 'waiting', 'name' => 'a1、b1']], array_map(fn (array $row) => $row['currentSummary'], $mine), $code);
        }
    }

    /**
     * 合議が可決すると、票を入れなかった人の未処理から消え、ほかの述語に合わなければ詳細が 404。票を入れた人は処理済に出る。
     */
    public function test_after_the_council_passes_a_non_voter_loses_the_case_and_voters_see_it_as_processed(): void
    {
        $this->addVersion($this->r1());
        $case = $this->applyOnRoute(['budget' => 100])[0];
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005', 'u0009'], 'owner' => ['u0011']]);
        $this->approve($case, 'council.1', 'u0004');

        $this->approve($case, 'council.2', 'u0005');

        $this->assertSame([], $this->appearances($case, 'u0009')['u0009']);
        $this->assertThrows(fn () => app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0009')), $case->id), ModelNotFoundException::class);
        $this->assertSame([['processed'], ['processed']], [$this->appearances($case, 'u0004')['u0004'], $this->appearances($case, 'u0005')['u0005']]);
        $this->assertSame(['pending'], $this->appearances($case, 'u0011')['u0011']);
    }

    /**
     * 詳細の nodes は写した並び(設計/ワークフロー/ルートの深さ/案件の操作/README.md 1.3): 縦配置の親の直後に子が番号の順で並び、
     * その後に次のルートのノードが来る。名前は表示名(展開の行は「{親} {子}」)。
     */
    public function test_the_detail_lists_the_children_right_after_their_parent_in_sequence_order(): void
    {
        $this->addVersion($this->r1());
        $case = $this->applyOnRoute(['budget' => 100])[0];
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005', 'u0009'], 'owner' => ['u0011']]);

        $nodes = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0002')), $case->id)['nodes'];

        $this->assertSame(['申請', '課長', '予算', '部長', '分岐終了', '関係部署の合議', '関係部署の合議 承認 1', '関係部署の合議 承認 2', '関係部署の合議 承認 3', '責任者'], array_column($nodes, 'name'));
    }

    /**
     * 分岐で省いたノードの(展開しなかった)人には出ない。
     */
    public function test_people_of_a_skipped_branch_never_see_the_case(): void
    {
        $this->addVersion($this->r1());
        $case = $this->applyOnRoute(['budget' => 100])[0];

        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0011']]);

        $this->assertSame('skipped', $this->shape($case)['manager']);
        $this->assertSame([], $this->appearances($case, 'u0003')['u0003']);
    }

    /**
     * タブの件数は案件の数: 並列の 2 つの枝で同じ人が処理対象者でも未処理は 1 件。詳細の can は現在のノードのどれかで許されれば真。
     */
    public function test_the_tab_count_counts_cases_even_when_one_person_is_the_target_of_two_nodes(): void
    {
        $this->addVersion([[$this->applyNode(),
            new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'),
            $this->approveNode('a1', 'u0002', ['apply']), $this->approveNode('b1', 'u0002', ['apply']),
            new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd),
        ], $this->chain(['apply', 'sync'], ['sync', 'a1'], ['sync', 'b1'], ['a1', 'sync_end'], ['b1', 'sync_end'])]);
        $context = $this->contextOf($this->seededUser('u0002'));
        $before = app(CaseListQuery::class)->counts($context)['pending'];
        $case = $this->applyOnRoute([])[0];

        $this->assertSame($before + 1, app(CaseListQuery::class)->counts($context)['pending']);
        $this->assertSame([['a1', true], ['b1', true]], array_map(fn (array $action) => [$action['name'], $action['approve']], app(CaseDetailQuery::class)->show($context, $case->id)['nodeActions']));
    }

    /**
     * 代理の行を 1 つ足すと、未処理の述語と操作の役割(承認の業務処理)がそろって変わる(代理の項 = docs の
     * 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.2 節)。代理の項だけで当たる人は代理元として処理する
     * (自分としては 422 principal_user_id)。
     */
    public function test_a_proxy_changes_the_pending_predicate_and_the_operation_role_together(): void
    {
        $this->addVersion($this->r2());
        $case = $this->applyOnRoute([])[0];
        [$principal, $proxy] = [$this->seededUser('u0004'), $this->seededUser('u0006')];
        $this->assertSame([], $this->appearances($case, 'u0006')['u0006']);
        WorkflowProxy::factory()->create([
            'principal_user_id' => $principal->id,
            'proxy_user_id' => $proxy->id,
            'registered_by_user_id' => $principal->id,
        ]);
        $nodeId = $this->labelIds($case)['b1'];
        $context = $this->contextOf($proxy);
        $approve = fn (?int $principalUserId) => app(ApproveCase::class)
            ->execute($context, $case->id, $this->revisionOf($case), $nodeId, null, [], $principalUserId);

        $this->assertSame(['pending'], $this->appearances($case, 'u0006')['u0006']);
        $isPrincipalError = fn (Throwable $e) => $e instanceof ValidationException
            && array_keys($e->errors()) === ['principal_user_id'];
        $this->assertThrows(fn () => $approve(null), $isPrincipalError);

        $approve($principal->id);

        $this->assertSame('done', $this->shape($case)['b1']);
    }

    /**
     * 引戻せるノードの blockedReason(画面.md 6 節、通しの回の T02): 申請者承認防止がオンのステップで申請者が差戻した後は、引戻すと業務処理が 422 にするので
     * 同じ文言(引戻しのボタンは出し、ダイアログが「決定」を押せなくする)。ほかの処理対象者が処理したステップ・防止がオフのステップを申請者が処理した後は null。
     * 詳細の props と一覧の行が同じ値を返す。
     */
    public function test_a_pull_back_node_carries_the_applicant_prevention_reason_only_for_the_applicant_on_a_preventing_step(): void
    {
        $department = [new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales'))];
        $this->addVersion([[$this->applyNode(),
            new RouteNodeInput('sales', '東京営業課', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, $department, ['ja' => '東京営業課'], preventsApplicantApproval: true, sendBackCodes: ['apply']),
            new RouteNodeInput('open', '東京営業課の確認', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, $department, ['ja' => '東京営業課の確認'], sendBackCodes: ['apply']),
            $this->approveNode('final', 'u0010', ['apply']),
        ], $this->chain(['apply', 'sales'], ['sales', 'open'], ['open', 'final'])]);
        $sentBack = $this->applyOnRoute([])[0];
        $this->sendBackOn($sentBack, 'sales', 'u0001', 'apply');
        $approvedByOther = $this->applyOnRoute([])[0];
        $this->approve($approvedByOther, 'sales', 'u0002');
        $approvedOnOpen = $this->applyOnRoute([])[0];
        $this->approve($approvedOnOpen, 'sales', 'u0002');
        $this->approve($approvedOnOpen, 'open', 'u0001');
        $blocked = '東京営業課の処理対象者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。';

        $this->assertSame([
            [true, [['東京営業課', $blocked]]],
            [true, [['東京営業課', null]]],
            [true, [['東京営業課の確認', null]]],
        ], [$this->pullBackOf($sentBack, 'u0001'), $this->pullBackOf($approvedByOther, 'u0002'), $this->pullBackOf($approvedOnOpen, 'u0001')]);
    }

    /**
     * 承認の行の指定は、承認したノードが指定するノードごとに写した並びで出し、0 人を指定したノードは people が空(画面は「指定: {ステップ} = 省略」=
     * 画面.md 7 節・案件の表.md 3.5、通しの回の T08)。指定するノードを持たない承認の行と、申請の行は空。
     */
    public function test_an_approval_lists_every_designated_step_and_a_step_designated_with_nobody_has_no_people(): void
    {
        $this->addVersion([[$this->applyNode(),
            $this->approveNode('chief', 'u0002', ['apply']),
            new RouteNodeInput('line', '順番の承認', WorkflowNodeKind::Serial, WorkflowTargetKind::Designated, designatorCode: 'chief', minAssignees: 0, maxAssignees: 3, sendBackCodes: ['apply', 'chief']),
            new RouteNodeInput('owner', '責任者', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated, designatorCode: 'chief', minAssignees: 1, maxAssignees: 1, sendBackCodes: ['apply', 'chief']),
            $this->approveNode('final', 'u0010', ['apply']),
        ], $this->chain(['apply', 'chief'], ['chief', 'line'], ['line', 'owner'], ['owner', 'final'])]);
        $case = $this->applyOnRoute([])[0];
        $this->approve($case, 'chief', 'u0002', ['line' => [], 'owner' => ['u0011']]);
        $this->approve($case, 'owner', 'u0011');

        $histories = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0002')), $case->id)['histories'];

        $this->assertSame('skipped', $this->shape($case)['line']);
        $this->assertSame([
            ['申請', WorkflowAction::Apply->value, []],
            ['chief', WorkflowAction::Approve->value, [['順番の承認', []], ['責任者', ['u0011']]]],
            ['責任者', WorkflowAction::Approve->value, []],
        ], array_map(fn (array $history) => [
            $history['nodeName'], $history['action'],
            array_map(fn (array $assignment) => [$assignment['nodeName'], array_column($assignment['people'], 'code')], $history['assignments']),
        ], $histories));
    }

    /**
     * 詳細の can.pullBack と pullBackNodes の [名前, blockedReason](一覧の行の can.pullBack・pullBackNodes が詳細と同じことも確かめる)。
     *
     * @return array{bool, list<array{string, string|null}>}
     */
    private function pullBackOf(WorkflowCase $case, string $userCode): array
    {
        $context = $this->contextOf($this->seededUser($userCode));
        $detail = app(CaseDetailQuery::class)->show($context, $case->id);
        $rows = array_merge(...array_map(fn (string $tab) => app(CaseListQuery::class)->list($context, $tab, null, null)['cases']['data'], ['pending', 'processed']));
        $row = array_values(array_filter($rows, fn (array $row) => $row['id'] === $case->id))[0] ?? $this->fail("{$userCode} の一覧に案件 {$case->id} の行が無い");
        $this->assertSame([$detail['can']['pullBack'], $detail['pullBackNodes']], [$row['can']['pullBack'], $row['pullBackNodes']]);

        return [$detail['can']['pullBack'], array_map(fn (array $node) => [$node['name'], $node['blockedReason']], $detail['pullBackNodes'])];
    }
}
