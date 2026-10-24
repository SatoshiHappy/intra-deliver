<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\OperatesAsCaseManager;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 詳細の履歴の添える 1 行の値(docs の 設計/ワークフロー/案件の運用/画面/案件の画面.md の 4 節、データモデル/表.md の 3.9 節): 担当変更の行の
 * users(振替先)、戻るの行の moveTargetName、指定の変更の行の users。問い合わせの数は案件のノードの数に比例しない。
 * 人は初期データの案件と代理に関わらない利用者(申請者 u0008)。案件の管理者 = u0004(システム管理者)。
 */
final class CaseDetailHistoryLinesTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;
    use OperatesAsCaseManager;
    use ReadsCaseLists;

    /**
     * 見本の詳細(WF-000003 を代理先 u0009 が開く)の問い合わせの上限(測った値 243 に余裕を見た値)。確認の面の展開は、確認を開く業務処理と
     * 同じ口のまとめ読みで、ユーザーの数によらない。
     */
    private const SEEDED_DETAIL_QUERY_LIMIT = 300;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo($this->operationDay()->setTime(12, 0));
    }

    /**
     * 担当変更(u0005 → u0007)の行は振替先、戻る(c2 → c1)の行は戻り先の表示名を持つ。ほかの行は空と null。
     * sendBackNodeName は差戻しの行だけの値なので、戻るの行を含めどれも null(差戻しの行は CaseDetailQueryTest が見る)。
     */
    public function test_transfer_and_move_back_rows_carry_their_lines(): void
    {
        $this->addLine($this->listNode('c1', ['u0005']), $this->listNode('c2', ['u0013']));
        $case = $this->applyAs($this->seededUser(self::APPLICANT));
        $this->transferOn($case, 'c1', 'u0005', $this->seededUser('u0007')->id);
        $this->approve($case, 'c1', 'u0007');
        $this->moveOn($case, 'c2', 'back', 'c1');

        $lines = $this->linesOf('u0004', $case);

        $this->assertSame([
            ['apply', [], null, null],
            ['transfer', [$this->seededUser('u0007')->id], null, null],
            ['approve', [], null, null],
            ['move_back', [], 'c1', null],
        ], $lines);
    }

    /**
     * 指定の変更(管理者が owner の指定を u0006 に変える)の行は、変えた後の人を持つ。行のステップ = 指定されるステップ。
     */
    public function test_a_reassign_row_carries_the_designated_people(): void
    {
        $owner = new RouteNodeInput(
            'owner', 'owner', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated,
            designatorCode: 'chief', minAssignees: 1, maxAssignees: 1, sendBackCodes: ['apply'],
        );
        $nodes = [$this->applyNode(), $this->listNode('chief', ['u0005']), $this->listNode('mid', ['u0013']), $owner];
        $this->addVersion([$nodes, $this->chain(['apply', 'chief'], ['chief', 'mid'], ['mid', 'owner'])]);
        $case = $this->applyAs($this->seededUser(self::APPLICANT));
        $this->approve($case, 'chief', 'u0005', ['owner' => ['u0007']]);
        $this->reassignOn($case, 'owner', ['u0006']);

        $histories = $this->show('u0004', $case)['histories'];
        $last = $histories[array_key_last($histories)];

        $this->assertSame(['reassign', 'owner', [$this->seededUser('u0006')->id]], [
            $last['action'], $last['nodeName'], array_column($last['users'], 'id'),
        ]);
    }

    /**
     * 案件のノードの数を 3 から 6 に増やしても、詳細の問い合わせの数は同じ(処理担当者 u0005 が先頭のステップを開く)。
     */
    public function test_the_queries_do_not_grow_with_the_nodes(): void
    {
        $counts = [];

        foreach (['2026-02-01' => 3, '2026-03-01' => 6] as $start => $count) {
            $codes = array_map(fn (int $index) => "c{$index}", range(1, $count));
            $nodes = array_map(fn (string $code) => $this->listNode($code, ['u0005']), $codes);
            $edges = $this->chain(...$this->lineOf($codes));
            $case = $this->caseOf($this->addVersion([[$this->applyNode(), ...$nodes], $edges], $start));
            $this->reach($this->nodesOf($case)[1], $this->seededUser('u0005'));
            // 判定を求める者の事実の控え(要求の中の 1 回)をそろえるため、1 回開いてから数える
            $this->show('u0005', $case);
            $counts[$count] = $this->queryCountOf(fn () => $this->show('u0005', $case));
        }

        $this->assertSame($counts[3], $counts[6]);
    }

    /**
     * 見本の詳細の問い合わせの数(代理元の分も読む)が上限の中。
     */
    public function test_the_seeded_detail_stays_within_the_query_limit(): void
    {
        $case = WorkflowCase::query()->where('case_number', 3)->sole();

        $count = $this->queryCountOf(fn () => $this->show('u0009', $case));

        $this->assertLessThanOrEqual(self::SEEDED_DETAIL_QUERY_LIMIT, $count);
    }

    /**
     * 申請からの一本道の辺の組。
     *
     * @param  list<string>  $codes
     * @return list<array{string, string}>
     */
    private function lineOf(array $codes): array
    {
        [$pairs, $from] = [[], 'apply'];

        foreach ($codes as $code) {
            $pairs[] = [$from, $code];
            $from = $code;
        }

        return $pairs;
    }

    /**
     * 履歴の行の [種別, users の id, moveTargetName, sendBackNodeName]。
     *
     * @return array<mixed>
     */
    private function linesOf(string $userCode, WorkflowCase $case): array
    {
        return array_map(
            fn (array $row) => [
                $row['action'], array_column($row['users'], 'id'), $row['moveTargetName'], $row['sendBackNodeName'],
            ],
            $this->show($userCode, $case)['histories'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function show(string $userCode, WorkflowCase $case): array
    {
        return app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser($userCode)), $case->id);
    }

    private function queryCountOf(callable $action): int
    {
        $count = 0;
        DB::listen(function (QueryExecuted $query) use (&$count): void {
            $count++;
        });
        $action();

        return $count;
    }
}
