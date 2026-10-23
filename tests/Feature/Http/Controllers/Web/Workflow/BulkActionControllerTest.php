<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\BulkResultRows;
use App\Actions\Workflow\CaseOperationErrors;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Workflow\CaseNumbers;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 一括承認・一括確認の入口(docs の 設計/ワークフロー/案件の運用/画面/README.md の 1.1 節、案件の操作/操作.md の 10.7 節、認可と一覧.md の 6 節の
 * 「HTTP の Feature テスト」): 全部が成功ならトーストと移り先のタブ、一部が止まればフラッシュ bulkResult(結果と対応は 10.7 節の分け方、閲覧できない
 * 行は申請名が null)、入力の誤りは元の画面へ戻りキーにエラーで何も書かない、画面が示した組と違う node_id・principal_user_id は止まる、
 * ゲストは業務処理の 404 で止まる。フロー = 申請(u0008)→ c1(u0005。確認する人 u0007)→ c2(u0006)。要求は画面と同じ Inertia の要求で送る。
 */
final class BulkActionControllerTest extends TransactionalTestCase
{
    use ReadsCaseLists;

    /** 画面(Inertia の router)が送る要求の印 */
    private const INERTIA = ['X-Inertia' => 'true'];

    private const LIST = '/workflow/cases?tab=pending';

    private const AGAIN = BulkResultRows::APPROVE_AGAIN;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $group = $this->confirmGroup('u0007');
        $this->addLine(
            $this->listNode('c1', ['u0005'], confirmSubjectGroupId: $group),
            $this->listNode('c2', ['u0006']),
        );
    }

    public function test_approves_all_with_a_toast_and_returns_to_the_pending_tab(): void
    {
        [$first, $second] = [$this->applied(), $this->applied()];
        $rows = [$this->screenRow($first), $this->screenRow($second)];
        $this->actingAs($this->seededUser('u0005'));

        $this->approveRequest(['cases' => $rows, 'comment' => 'まとめて承認します。'])
            ->assertStatus(302)->assertSessionHasNoErrors()->assertInertiaFlashMissing('bulkResult')
            ->assertRedirect(route('workflow.cases.index', ['tab' => 'pending']))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 件を承認しました']);

        $this->assertSame(['done', 'waiting'], [$this->stateOf($first, 'c1'), $this->stateOf($second, 'c2')]);
        $nodeId = $this->caseNode($first, 'c1')->id;
        $approval = WorkflowCaseHistory::query()->where('workflow_case_node_id', $nodeId)->sole();
        $this->assertSame('まとめて承認します。', $approval->comment);
        $this->get(route('workflow.cases.index', ['tab' => 'pending']))->assertOk();
    }

    /**
     * 止まった案件で止め、結果の値を渡す: 承認できた = 承認済み、画面が古いだけ = 未処理・もう一度承認、止まった後の案件 = 未処理・もう一度承認、
     * 無い案件 = 未処理・—(申請名・番号・フロー名が null)。
     */
    public function test_a_stopped_bulk_passes_the_result_of_each_case(): void
    {
        [$first, $stale, $after] = [$this->applied(), $this->applied(), $this->applied()];
        $staleRow = ['revision' => $this->revisionOf($stale) + 1] + $this->screenRow($stale);
        $this->actingAs($this->seededUser('u0005'));

        $this->approveRequest(['cases' => [$this->screenRow($first), $staleRow, $this->screenRow($after)]])
            ->assertStatus(302)->assertInertiaFlashMissing('toast')
            ->assertRedirect(route('workflow.cases.index', ['tab' => 'pending']))
            ->assertInertiaFlash('bulkResult', $this->bulkResultOf('approve', 1, [
                $this->resultRow($first, 'approved', null),
                $this->resultRow($stale, 'pending', self::AGAIN),
                $this->resultRow($after, 'pending', self::AGAIN),
            ]));
        $this->assertSame(['waiting', 'waiting'], [$this->stateOf($stale, 'c1'), $this->stateOf($after, 'c1')]);

        $missing = ['id' => 999999999] + $this->screenRow($stale);
        $this->approveRequest(['cases' => [$missing]])->assertStatus(302)
            ->assertInertiaFlash('bulkResult', $this->bulkResultOf('approve', 0, [$this->hiddenRow(999999999)]));
    }

    /**
     * 画面が示した組と違うノード・処理担当者の行は承認せずに止まる(画面が古いだけなので「もう一度承認してください。」)。
     */
    public function test_a_pair_different_from_the_screen_stops(): void
    {
        [$otherNode, $otherPrincipal] = [$this->applied(), $this->applied()];
        $this->actingAs($this->seededUser('u0005'));
        $rows = [
            [$otherNode, $this->rowFor($otherNode, $this->caseNode($otherNode, 'c2')->id)],
            [$otherPrincipal, $this->rowFor(
                $otherPrincipal,
                $this->caseNode($otherPrincipal, 'c1')->id,
                $this->seededUser('u0006')->id,
            )],
        ];

        foreach ($rows as [$case, $row]) {
            $expected = $this->bulkResultOf('approve', 0, [$this->resultRow($case, 'pending', self::AGAIN)]);
            $this->approveRequest(['cases' => [$row]])->assertStatus(302)->assertInertiaFlash('bulkResult', $expected);
            $this->assertSame('waiting', $this->stateOf($case, 'c1'));
        }
    }

    /**
     * 0 件・21 件以上・形の誤り・コメントの誤りは、元の画面へ戻りキーにエラーが付き、何も処理しない。
     */
    public function test_input_errors_return_to_the_screen_and_process_nothing(): void
    {
        $case = $this->applied();
        $row = $this->screenRow($case);
        $this->actingAs($this->seededUser('u0005'));
        $histories = WorkflowCaseHistory::query()->count();
        $inputs = [
            'cases' => [
                ['cases' => []],
                ['cases' => array_fill(0, 21, $row)],
                ['cases' => [['id' => (string) $case->id] + $row]],
                [],
            ],
            'comment' => [
                ['cases' => [$row], 'comment' => ['x']],
                ['cases' => [$row], 'comment' => str_repeat('あ', 4001)],
            ],
        ];

        foreach ($inputs as $key => $wrongs) {
            foreach ($wrongs as $input) {
                $response = $this->from(self::LIST)->approveRequest($input);
                $response->assertRedirect(self::LIST)->assertSessionHasErrors([$key]);
            }
        }

        $this->from(self::LIST)->confirmRequest('x')
            ->assertRedirect(self::LIST)->assertSessionHasErrors(['case_ids' => CaseOperationErrors::BULK_REQUIRED]);
        $this->assertSame($histories, WorkflowCaseHistory::query()->count());
        $this->assertSame($row['revision'], $this->revisionOf($case));
    }

    /**
     * 一括確認: 全部が確認できればトーストと未確認のタブ。閲覧できない案件で止まると、その行は申請名が null で、後ろの案件は「もう一度確認」。
     */
    public function test_confirms_in_bulk(): void
    {
        [$first, $second, $hidden, $after] = array_map(fn () => $this->applied(), range(1, 4));

        foreach ([$first, $second, $after] as $case) {
            $this->approve($case, 'c1', 'u0005');
        }

        $this->actingAs($this->seededUser('u0007'));

        $this->confirmRequest([$first->id, $second->id])->assertStatus(302)
            ->assertRedirect(route('workflow.cases.index', ['tab' => 'unconfirmed']))
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '2 件を確認しました']);
        $this->confirmRequest([$hidden->id, $after->id])->assertStatus(302)
            ->assertInertiaFlash('bulkResult', $this->bulkResultOf('confirm', 0, [
                $this->hiddenRow($hidden->id),
                $this->resultRow($after, 'pending', BulkResultRows::CONFIRM_AGAIN),
            ]));
    }

    /**
     * ゲストは入口を通れるが、業務処理の閲覧の判定(404)で止まる(何も処理しない。行は申請名が null)。
     */
    public function test_a_guest_stops_at_the_first_case(): void
    {
        $case = $this->applied();
        $expected = fn (string $action) => $this->bulkResultOf($action, 0, [$this->hiddenRow($case->id)]);

        $this->approveRequest(['cases' => [$this->screenRow($case)]])->assertStatus(302)
            ->assertInertiaFlash('bulkResult', $expected('approve'));
        $this->confirmRequest([$case->id])->assertStatus(302)->assertInertiaFlash('bulkResult', $expected('confirm'));
        $this->assertSame('waiting', $this->stateOf($case, 'c1'));
    }

    private function applied(): WorkflowCase
    {
        return $this->applyAs($this->seededUser(self::APPLICANT));
    }

    /**
     * u0005 の画面が送る 1 行(行の組の写し)。
     *
     * @return array{id: int, revision: int, node_id: int, principal_user_id: int|null}
     */
    private function screenRow(WorkflowCase $case): array
    {
        return $this->screenRowOf($case, $this->seededUser('u0005'));
    }

    private function stateOf(WorkflowCase $case, string $code): string
    {
        return $this->caseNode($case, $code)->state->value;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return TestResponse<Response>
     */
    private function approveRequest(array $input): TestResponse
    {
        return $this->post('/workflow/cases/bulk-approve', $input, self::INERTIA);
    }

    /**
     * @return TestResponse<Response>
     */
    private function confirmRequest(mixed $caseIds): TestResponse
    {
        return $this->post('/workflow/cases/bulk-confirm', ['case_ids' => $caseIds], self::INERTIA);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function bulkResultOf(string $action, int $succeeded, array $rows): array
    {
        return ['action' => $action, 'total' => count($rows), 'succeeded' => $succeeded, 'rows' => $rows];
    }

    /**
     * 閲覧できない・無い案件の結果の行(未処理・対応なし)。
     *
     * @return array<string, mixed>
     */
    private function hiddenRow(int $caseId): array
    {
        return [
            'caseId' => $caseId, 'name' => null, 'number' => null, 'flowName' => null,
            'result' => 'pending', 'guidance' => null,
        ];
    }

    /**
     * 閲覧できる案件の結果の行。
     *
     * @return array<string, mixed>
     */
    private function resultRow(WorkflowCase $case, string $result, ?string $guidance): array
    {
        $fresh = WorkflowCase::query()->with('workflowFlow')->findOrFail($case->id);

        return [
            'caseId' => $case->id,
            'name' => $fresh->name,
            'number' => CaseNumbers::format((int) $fresh->case_number),
            'flowName' => $fresh->workflowFlow?->name,
            'result' => $result,
            'guidance' => $guidance,
        ];
    }
}
