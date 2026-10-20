<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Models\Workflow\WorkflowCase;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * 「書き込み → 移り先の GET」(docs の 設計/ワークフロー/認可と一覧.md 7 節)を案件の処理で確かめる HTTP のテストの手助け: 案件の詳細から処理を送る
 * (画面と同じ Inertia の要求。元の画面 = 詳細)、移り先の詳細を利用者として開いて props を読む、ノードの名札(RunsRoutePlans::labelIds)で状態を読む。
 * UsesSeededCases・OperatesOnRoutes と一緒に使う。
 *
 * @phpstan-type DetailProps array{case: array{state: string, completionReason: string|null, currentSummary: array{state: string, name: string}|null, version: int, baseDate: string|null}, nodes: list<array{id: int, name: string, kind: string, state: string}>, edges: list<array{from: int, to: int, taken: bool|null}>, councils: list<array{approved: int, required: int, passed: bool, rows: list<array{action: string|null, person: array{code: string, isMe: bool}|null}>}>, histories: list<array{action: string, assignments: list<array{nodeName: string, people: list<array{code: string}>}>}>, can: array{pullBack: bool, reapply: bool, withdraw: bool}, nodeActions: list<array{nodeId: int, name: string, approve: bool, designations: list<array{name: string, initial: list<array{code: string}>}>}>, pullBackNodes: list<array{id: int, name: string, blockedReason: string|null}>}
 */
trait FollowsCaseWrites
{
    /**
     * 利用者として案件の詳細から処理を送る(revision = 今の改訂番号、コメントなし)。
     *
     * @param  array<string, mixed>  $fields  node_id・send_back_node_id・assignments
     * @return TestResponse<Response>
     */
    private function act(string $userCode, int $caseId, string $action, array $fields): TestResponse
    {
        $this->actingAsUser($userCode);

        return $this->from("/workflow/cases/{$caseId}")
            ->post("/workflow/cases/{$caseId}/{$action}", ['revision' => WorkflowCase::query()->findOrFail($caseId)->revision, 'comment' => null, ...$fields], ['X-Inertia' => 'true']);
    }

    /**
     * 処理が通り、案件の詳細へ移る(302)。
     *
     * @param  TestResponse<Response>  $response
     */
    private function assertLandsOnDetail(TestResponse $response, int $caseId): void
    {
        $response->assertStatus(302)->assertSessionHasNoErrors()->assertRedirect("/workflow/cases/{$caseId}");
    }

    /**
     * 利用者として詳細を開き(200)、props を返す。
     *
     * @return DetailProps
     */
    private function detail(string $userCode, int $caseId): array
    {
        $this->actingAsUser($userCode);

        return $this->get("/workflow/cases/{$caseId}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Workflow/CaseShow'))->viewData('page')['props'];
    }

    private function assertDetailNotFound(string $userCode, int $caseId): void
    {
        $this->actingAsUser($userCode);
        $this->get("/workflow/cases/{$caseId}")->assertNotFound();
    }

    /**
     * 詳細の nodes のうち、名札のノードの状態(名札 → 状態)。
     *
     * @param  DetailProps  $props
     * @return array<string, string>
     */
    private function statesIn(array $props, int $caseId, string ...$labels): array
    {
        $ids = $this->labelIds(WorkflowCase::query()->findOrFail($caseId));
        $states = array_column($props['nodes'], 'state', 'id');

        return array_combine($labels, array_map(fn (string $label) => $states[$ids[$label]], $labels));
    }

    private function nodeOf(int $caseId, string $label): int
    {
        return $this->labelIds(WorkflowCase::query()->findOrFail($caseId))[$label];
    }

    /**
     * 名札 → 人のコードの指定を、送る形(指定されるノードの id → ユーザーの id)にする。
     *
     * @param  array<string, list<string>>  $byLabel
     * @return array<int, list<int>>
     */
    private function assignmentsFor(int $caseId, array $byLabel): array
    {
        $result = [];

        foreach ($byLabel as $label => $codes) {
            $result[$this->nodeOf($caseId, $label)] = array_map(fn (string $code) => $this->seededUser($code)->id, $codes);
        }

        return $result;
    }

    /**
     * @param  DetailProps  $props
     * @return list<array{string, list<string>}> 最後の履歴の行の指定(ステップ名, 人のコード)
     */
    private function lastAssignments(array $props): array
    {
        $histories = $props['histories'];
        $last = end($histories) ?: $this->fail('履歴の行が無い');

        return array_map(fn (array $assignment) => [$assignment['nodeName'], array_column($assignment['people'], 'code')], $last['assignments']);
    }

    /**
     * 書き込みが拒まれたときに変わっていないことを確かめる値(ノードの形・ワークフローの表の行の数・改訂番号)。
     *
     * @return array{array<string, string>, array<string, int>, int}
     */
    private function snapshotOf(int $caseId): array
    {
        $case = WorkflowCase::query()->findOrFail($caseId);

        return [$this->shape($case), $this->workflowCounts(), $case->revision];
    }
}
