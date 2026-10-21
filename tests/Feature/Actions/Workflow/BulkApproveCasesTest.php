<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\BulkResultRows;
use App\Actions\Workflow\CaseOperationErrors;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowNoticeType;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowProxy;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\OperatesHolds;
use Tests\Support\ReadsCaseNotices;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsBulkOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 一括承認(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節、同時実行と検査.md の 12 節・13 節・14.2 節)。
 * フロー = 申請(u0001)→ 承認 1(u0002 と u0003。どちらか 1 人で進む)→ 承認 2(u0004)。参照者 = 経理部(u0010 は閲覧できる)。
 * 申請者承認防止の代理の項は今日を時計から読むので、時計を操作の基準日に合わせる。
 */
final class BulkApproveCasesTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use CatchesValidationErrors;
    use OperatesHolds;
    use ReadsCaseNotices;
    use RegistersProxies;
    use RunsBulkOperations;

    private const AGAIN = BulkResultRows::APPROVE_AGAIN;

    private const OPEN = BulkResultRows::OPEN_CASE;

    private const DONE = BulkResultRows::NO_ACTION_NEEDED;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->createFlow(approvals: [
            [$this->userCondition('u0002'), $this->userCondition('u0003')],
            [$this->userCondition('u0004')],
        ]);
    }

    /**
     * 並びの順に 1 件ずつ承認し、途中の失敗で止まる(前は確定、後は処理しない)。ほかの人の承認が先に確定した案件は処理済みで止まる。
     * 代理元として承認した履歴は処理者 = 代理元・実行者 = 自分で、1 件ずつの承認が単独の承認と同じ通知を作る(起こした人 = 実行者)。
     * 操作する人 = u0002 の承認の代理先 u0010(参照者なので、当たらなくなっても閲覧できる)。
     */
    public function test_approves_in_order_and_stops_at_the_first_failure(): void
    {
        $proxy = $this->u('u0010');
        $this->addProxy($this->u('u0002'), $proxy);
        [$first, $taken, $last] = [$this->applied(), $this->applied(), $this->applied()];
        $rows = array_map(fn (WorkflowCase $case) => $this->screenRowOf($case, $proxy), [$first, $taken, $last]);
        $this->operate(ApproveCase::class, $this->u('u0003'), $taken);
        $lastRevision = $this->revisionOf($last);
        $lastNotice = $this->lastNoticeId();

        $result = $this->bulkApprove($proxy, $rows, '確認しました');

        $this->assertSame([
            [$first->id, 'approved', null, true],
            [$taken->id, 'processed', self::DONE, true],
            [$last->id, 'pending', self::AGAIN, true],
        ], $this->resultLines($result));
        $this->assertSame([3, 1], [$result->total(), $result->succeeded()]);
        $this->assertSame(['u0002', 'u0010', '確認しました'], $this->lastApproval($first));
        $this->assertSame($lastRevision, $this->revisionOf($last));
        $step = ['step_name' => '承認2', 'step_kind' => 'approve'];
        $expected = [$this->caseNotice(WorkflowNoticeType::ProcessRequest, 'u0004', 'u0010', $first, $step)];
        $this->assertSame($expected, $this->noticesSince($lastNotice));
    }

    /**
     * 選択の誤り(0 件・21 件・重複・形の違い)は 422 cases、コメントの誤りは 422 comment で、どの案件も処理しない。
     */
    public function test_input_errors_process_nothing(): void
    {
        $case = $this->applied();
        $row = $this->screenRowOf($case, $this->u('u0002'));
        $required = ['cases' => CaseOperationErrors::BULK_REQUIRED];
        $inputs = [
            '0 件' => [[], $required],
            '21 件' => [array_fill(0, 21, $row), ['cases' => CaseOperationErrors::BULK_TOO_MANY]],
            '重複' => [[$row, $row], $required],
            '並びでない' => [['a' => $row], $required],
            'node_id が無い' => [[['id' => $case->id, 'revision' => $row['revision']]], $required],
            'id が文字列' => [[[...$row, 'id' => (string) $case->id]], $required],
            '代理元が文字列' => [[[...$row, 'principal_user_id' => 'u0003']], $required],
            '配列でない' => ['1', $required],
        ];
        $before = [$this->workflowCounts(), $this->revisionOf($case)];

        foreach ($inputs as $label => [$cases, $expected]) {
            $errors = $this->validationErrors(fn () => $this->bulkApprove($this->u('u0002'), $cases));
            $this->assertSame($expected, $errors, $label);
        }

        $long = str_repeat('あ', 4001);
        $errors = $this->validationErrors(fn () => $this->bulkApprove($this->u('u0002'), [$row], $long));
        $this->assertSame(['comment' => CaseOperationErrors::COMMENT_TOO_LONG], $errors);
        $this->assertSame($before, [$this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * 画面が確かめた組と処理の時の組が違うと(改訂番号は同じ)止まり、承認しない: 代理の削除で当たらなくなった → 処理済み、代理の登録で組が 2 つ →
     * 申請を開いて、代理の差し替えで別の代理元の組 → もう一度。別のノード・自分の代理元でない人を送っても止まり、何も変わらない。
     */
    public function test_a_pair_different_from_the_screen_stops_without_approving(): void
    {
        $proxy = $this->u('u0010');
        $changes = [
            '代理の削除' => [fn (WorkflowProxy $row) => $row->delete(), self::DONE, 'processed'],
            '代理の登録' => [fn () => $this->addProxy($this->u('u0003'), $proxy), self::OPEN, 'pending'],
            '代理の差し替え' => [function (WorkflowProxy $row) use ($proxy): void {
                $row->delete();
                $this->addProxy($this->u('u0003'), $proxy);
            }, self::AGAIN, 'pending'],
        ];

        foreach ($changes as $label => [$change, $guidance, $outcome]) {
            $proxyRow = $this->addProxy($this->u('u0002'), $proxy);
            $case = $this->applied();
            $row = $this->screenRowOf($case, $proxy);
            $change($proxyRow);
            $before = [$this->workflowCounts(), $this->revisionOf($case)];

            $result = $this->bulkApprove($proxy, [$row]);

            $this->assertSame([[$case->id, $outcome, $guidance, true]], $this->resultLines($result), $label);
            $this->assertSame($before, [$this->workflowCounts(), $this->revisionOf($case)], $label);
            WorkflowProxy::query()->where('proxy_user_id', $proxy->id)->delete();
        }

        $case = $this->applied();
        $applyNodeId = $this->caseNode($case, 'apply')->id;
        $approvalNodeId = $this->caseNode($case, 'approval1')->id;
        $before = [$this->workflowCounts(), $this->revisionOf($case)];

        $otherNode = $this->rowFor($case, $applyNodeId);
        $notPrincipal = $this->rowFor($case, $approvalNodeId, $this->u('u0003')->id);

        foreach ([$otherNode, $notPrincipal] as $row) {
            $result = $this->bulkApprove($this->u('u0002'), [$row]);
            $this->assertSame([[$case->id, 'pending', self::AGAIN, true]], $this->resultLines($result));
        }

        $this->assertSame($before, [$this->workflowCounts(), $this->revisionOf($case)]);
    }

    /**
     * 結果の分け方の枝: 閲覧できない・無い(申請名を持たない)、改訂番号だけが古く今は選べる(もう一度)、ほかの人の保留(申請を開いて)。
     * 止まった後ろの閲覧できない案件・消された案件の行も申請名を持たない。
     */
    public function test_each_branch_of_the_result(): void
    {
        $missing = (int) WorkflowCase::query()->max('id') + 1;
        $this->createFlow('wf_other', approvals: [[$this->userCondition('u0003')]]);
        $hidden = $this->applyAs($this->u('u0001'), 'wf_other');
        $stale = $this->applied();
        $staleRow = $this->screenRowOf($stale, $this->u('u0002'));
        $this->holdOn($stale, 'approval1', 'u0003');
        $this->releaseOn($stale, 'approval1', 'u0003');
        $held = $this->applied();
        $heldRow = $this->screenRowOf($held, $this->u('u0002'));
        $this->holdOn($held, 'approval1', 'u0003');
        $hiddenRow = ['id' => $hidden->id, 'revision' => 1, 'node_id' => 1, 'principal_user_id' => null];
        $missingRow = [...$hiddenRow, 'id' => $missing];

        $results = [
            $this->resultLines($this->bulkApprove($this->u('u0002'), [$hiddenRow, $staleRow])),
            $this->resultLines($this->bulkApprove($this->u('u0002'), [$staleRow, $missingRow])),
            $this->resultLines($this->bulkApprove($this->u('u0002'), [$heldRow, $hiddenRow, $missingRow])),
        ];

        $this->assertSame([
            [[$hidden->id, 'pending', null, false], [$stale->id, 'pending', self::AGAIN, true]],
            [[$stale->id, 'pending', self::AGAIN, true], [$missing, 'pending', null, false]],
            [
                [$held->id, 'pending', self::OPEN, true],
                [$hidden->id, 'pending', null, false],
                [$missing, 'pending', null, false],
            ],
        ], $results);
    }

    /**
     * 次のノードの展開が 0 人で承認が拒まれた案件は「申請を開いて処理してください。」。
     */
    public function test_no_one_on_the_next_node_asks_to_open_the_case(): void
    {
        $this->createFlow('wf_empty', approvals: [[$this->userCondition('u0002')], [$this->userCondition('u0014')]]);
        $case = $this->applyAs($this->u('u0001'), 'wf_empty');
        $before = $this->workflowCounts();

        $result = $this->bulkApprove($this->u('u0002'), [$this->screenRowOf($case, $this->u('u0002'))]);

        $this->assertSame([[$case->id, 'pending', self::OPEN, true]], $this->resultLines($result));
        $this->assertSame($before, $this->workflowCounts());
    }

    /**
     * 同じ人が同じ一括を 2 回送ると、2 回目は最初の案件で止まる(処理済み)。
     */
    public function test_sending_the_same_bulk_twice_stops_at_the_first_case(): void
    {
        [$first, $second] = [$this->applied(), $this->applied()];
        $rows = [$this->screenRowOf($first, $this->u('u0002')), $this->screenRowOf($second, $this->u('u0002'))];
        $this->bulkApprove($this->u('u0002'), $rows);

        $result = $this->bulkApprove($this->u('u0002'), $rows);

        $this->assertSame([
            [$first->id, 'processed', self::DONE, true],
            [$second->id, 'pending', self::AGAIN, true],
        ], $this->resultLines($result));
    }

    /**
     * 代理 × 申請者承認防止(README.md の 5 節の表の 5 行): 拒む 4 行は止まって「申請を開いて処理してください。」、C 本人は承認できる。
     * 申請者 A = u0001・A の代理先 B = u0002・第三者 C = u0003(A は C の代理先)。防止オンのステップ = 東京営業課(A・B)と C。
     */
    public function test_the_five_rows_of_applicant_approval_prevention(): void
    {
        [$a, $b, $c] = [$this->u('u0001'), $this->u('u0002'), $this->u('u0003')];
        $this->addVersion($this->salesRoute());
        $this->addProxy($a, $b);
        $this->addProxy($c, $a);
        $case = $this->applyAs($a);
        $nodeId = $this->caseNode($case, 'sales')->id;
        $rows = [
            'A 本人' => [$a, null],
            'B が A として' => [$b, $a->id],
            'B が本人として' => [$b, null],
            'A が C として' => [$a, $c->id],
            'C 本人' => [$c, null],
        ];
        $actual = [];

        foreach ($rows as $label => [$user, $principal]) {
            DB::beginTransaction();

            try {
                $lines = $this->resultLines($this->bulkApprove($user, [$this->rowFor($case, $nodeId, $principal)]));
                $actual[$label] = [$lines[0][1], $lines[0][2]];
            } finally {
                DB::rollBack();
            }
        }

        $this->assertSame([
            'A 本人' => ['pending', self::OPEN],
            'B が A として' => ['pending', self::OPEN],
            'B が本人として' => ['pending', self::OPEN],
            'A が C として' => ['pending', self::OPEN],
            'C 本人' => ['approved', null],
        ], $actual);
    }

    private function applied(): WorkflowCase
    {
        return $this->applyAs($this->u('u0001'));
    }

    private function u(string $code): User
    {
        return $this->seededUser($code);
    }

    /**
     * 最新の履歴の行の [処理者のコード, 実行者のコード, コメント]。
     *
     * @return array{string|null, string|null, string|null}
     */
    private function lastApproval(WorkflowCase $case): array
    {
        $history = WorkflowCaseHistory::query()->with(['actor', 'executor'])->where('workflow_case_id', $case->id)
            ->latest('id')->firstOrFail();

        return [$history->actor?->code, $history->executor?->code, $history->comment];
    }

    /**
     * 申請 → 営業(東京営業課と u0003。防止オン)→ final(u0010)。
     *
     * @return array{list<RouteNodeInput>, list<RouteEdgeInput>}
     */
    private function salesRoute(): array
    {
        $sales = new RouteNodeInput(
            'sales',
            '営業',
            WorkflowNodeKind::Approve,
            WorkflowTargetKind::Fixed,
            [
                new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales')),
                $this->userCondition('u0003'),
            ],
            ['ja' => '営業'],
            preventsApplicantApproval: true,
            sendBackCodes: ['apply'],
        );
        $final = $this->approveNode('final', 'u0010', ['apply', 'sales']);

        return [[$this->applyNode(), $sales, $final], $this->chain(['apply', 'sales'], ['sales', 'final'])];
    }
}
