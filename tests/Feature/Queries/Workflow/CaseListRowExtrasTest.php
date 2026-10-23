<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use App\Queries\Workflow\WorkflowPeople;
use Carbon\CarbonImmutable;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 案件一覧の未処理の行の項目(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節・6 節の「行の項目」、型 = 画面/README.md の 1.2 節):
 * 現在のステップの保留中(currentNodes と currentSummary.state)、代理元、経過日数・期限・期限超過(案件の操作/README.md の 1.4 節の案件ごとの値。
 * 対象 = 未処理の集まり)、一括承認の組(業務処理の組と同じ)。期待は文書の表と文から書いた。
 *
 * @phpstan-import-type Person from WorkflowPeople
 */
final class CaseListRowExtrasTest extends TransactionalTestCase
{
    use ReadsCaseLists;

    /**
     * 現在のノードが 2 つで片方がほかの人の保留中: 保留中の札はそのノードにだけ付き、案件の札は承認待ち。組は自分のノード(保留者側は保留中の
     * ノード)で、処理の時の組と同じ。両方が保留中になると案件の札は保留中(一覧の行と詳細の currentSummary が同じ)。
     */
    public function test_a_held_node_and_the_label_of_the_case(): void
    {
        $this->addPair($this->listNode('a', ['u0005']), $this->listNode('b', ['u0006']));
        $case = $this->applyAt('2026-10-13 01:00:00');
        [$a, $b] = [$this->caseNode($case, 'a')->id, $this->caseNode($case, 'b')->id];
        $this->holdOn($case, 'b', 'u0006');
        $row = $this->rowOf('u0005', 'pending', $case);

        $nodes = [['id' => $a, 'name' => 'a', 'state' => 'waiting'], ['id' => $b, 'name' => 'b', 'state' => 'hold']];
        $this->assertSame($nodes, $row['currentNodes']);
        $this->assertSame(['state' => 'waiting', 'name' => 'a、b'], $row['currentSummary']);
        $this->assertPair($case, 'u0005', ['nodeId' => $a, 'nodeName' => 'a', 'principal' => null]);
        $this->assertPair($case, 'u0006', ['nodeId' => $b, 'nodeName' => 'b', 'principal' => null]);

        $this->holdOn($case, 'a', 'u0005');

        $held = ['state' => 'hold', 'name' => 'a、b'];
        $this->assertSame($held, $this->rowOf('u0005', 'pending', $case)['currentSummary']);
        $this->assertSame($held, $this->rowOf(self::APPLICANT, 'processed', $case)['currentSummary']);
        $this->assertSame($held, $this->detailSummary('u0005', $case));
    }

    /**
     * 代理元 = 自分が本人として当たらないノードの代理元(2 人なら 2 人。本人として当たるノードの代理元は出さない)。代理元が 2 人のノードは組が
     * 2 つで選べず、代理元が 1 人なら代理元として選べる(処理の時の組と同じ)。u0005 は u0006・u0007 の承認の代理先。
     */
    public function test_the_principals_and_the_pairs_of_a_proxy(): void
    {
        $this->createFlow('wf_two', '二人', [[$this->userCondition('u0006'), $this->userCondition('u0007')]]);
        $this->createFlow('wf_own', '本人', [[$this->userCondition('u0005'), $this->userCondition('u0006')]]);
        $this->createFlow('wf_one', '一人', [[$this->userCondition('u0006')]]);
        [$proxy, $u0006, $u0007] = array_map($this->seededUser(...), ['u0005', 'u0006', 'u0007']);
        $this->addProxy($u0006, $proxy);
        $this->addProxy($u0007, $proxy);
        $applicant = $this->seededUser(self::APPLICANT);
        $applyOn = fn (string $flow) => $this->applyAs($applicant, $flow);
        [$two, $own, $one] = array_map($applyOn, ['wf_two', 'wf_own', 'wf_one']);
        $people = [
            'u0006' => ['id' => $u0006->id, 'code' => 'u0006', 'name' => '山本 浩二'],
            'u0007' => ['id' => $u0007->id, 'code' => 'u0007', 'name' => '小林 直樹'],
        ];

        $this->assertSame([$people['u0006'], $people['u0007']], $this->rowOf('u0005', 'pending', $two)['principals']);
        $this->assertPair($two, 'u0005', null);
        $this->assertSame([], $this->rowOf('u0005', 'pending', $own)['principals']);
        $ownNode = $this->caseNode($own, 'approval1')->id;
        $this->assertPair($own, 'u0005', ['nodeId' => $ownNode, 'nodeName' => '承認1', 'principal' => null]);
        $this->assertSame([$people['u0006']], $this->rowOf('u0005', 'pending', $one)['principals']);
        $oneNode = $this->caseNode($one, 'approval1')->id;
        $this->assertPair($one, 'u0005', ['nodeId' => $oneNode, 'nodeName' => '承認1', 'principal' => $people['u0006']]);
    }

    /**
     * 経過日数 = 到達した日から今日まで、期限 = 到達した日 + 期限の日数、超過 = 今日 > 期限(当日は超過でない)。対象は未処理の集まりだけ
     * (同じ案件のほかの人のノードの期限は見ない)。期限の日数の無いノードだけなら期限は「—」。今日 = 2026-10-13。
     */
    public function test_the_elapsed_days_and_the_deadline_of_the_pending_nodes(): void
    {
        $this->createFlow('wf_free', '期限なし', [[$this->userCondition('u0005')]]);
        $this->addPair($this->listNode('a', ['u0005'], 2), $this->listNode('b', ['u0006'], 0));
        $overdue = $this->applyAt('2026-10-10 03:00:00');
        $today = $this->applyAt('2026-10-11 03:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 03:00:00'));
        $free = $this->applyAs($this->seededUser(self::APPLICANT), 'wf_free');

        $this->assertSame(['2026-10-10', 3, '2026-10-12', true], $this->deadlineOf('u0005', $overdue));
        $this->assertSame(['2026-10-11', 2, '2026-10-13', false], $this->deadlineOf('u0005', $today));
        $this->assertSame(['2026-10-12', 1, null, false], $this->deadlineOf('u0005', $free));
        $this->assertSame(['2026-10-11', 2, '2026-10-11', true], $this->deadlineOf('u0006', $today));
    }

    /**
     * 行の組が処理の時の組({@see RunsBulkOperations::processingApprovalOf()})と同じで、can.bulkApprove は組があるときだけ真。
     *
     * @param  array{nodeId: int, nodeName: string, principal: Person|null}|null  $expected
     */
    private function assertPair(WorkflowCase $case, string $userCode, ?array $expected): void
    {
        $row = $this->rowOf($userCode, 'pending', $case);
        $processing = $this->processingApprovalOf($case, $this->seededUser($userCode));

        $this->assertSame($expected, $row['bulkApproval'], "{$userCode} の行の組");
        $this->assertSame($expected !== null, $row['can']['bulkApprove']);
        $this->assertSame($expected['nodeId'] ?? null, $processing?->nodeId, "{$userCode} の処理の時の組");
        $this->assertSame($expected['principal']['id'] ?? null, $processing?->principalUserId);
    }

    /**
     * 未処理の行の期限 [到達した日, 経過日数, 期限日, 期限超過](キーは型の 4 つ)。
     *
     * @return list<mixed>
     */
    private function deadlineOf(string $userCode, WorkflowCase $case): array
    {
        $deadline = $this->rowOf($userCode, 'pending', $case)['deadline'] ?? [];
        $this->assertSame(['reachedOn', 'elapsedDays', 'dueOn', 'overdue'], array_keys($deadline));

        return array_values($deadline);
    }

    /**
     * @return array{state: string, name: string}|null
     */
    private function detailSummary(string $userCode, WorkflowCase $case): ?array
    {
        $context = $this->contextOf($this->seededUser($userCode));

        return app(CaseDetailQuery::class)->show($context, $case->id)['case']['currentSummary'];
    }
}
