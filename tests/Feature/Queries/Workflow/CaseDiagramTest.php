<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseDetailQuery;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Tests\Support\OperatesOnRoutes;
use Tests\Support\TransactionalTestCase;

/**
 * 案件の詳細のフロー情報と合議の進み具合(docs の 設計/ワークフロー/ルートの深さ/画面/README.md 1.2・画面/案件の側の画面.md 7 節・画面/図と意匠とE2E.md 8 節、
 * 認可と一覧.md 2 節の写しの表、案件の操作/README.md 2.5):
 * nodes は図の形(写した並び・親と番号・短い文・人・縦配置の完了の条件・状態の語・処理の種別と日時)、edges は分岐の評価(taken)、councils は縦配置の進み具合。
 * 期待値は 初期データ.md 4 節の見本(WF-000009〜000012)と設計の表から書く。初期データは DatabaseSeeder が 1 回入れたもの。見本に無い場面(否認の票の途中・
 * 指定の前・可決の後に省いた子の人)は、版 3 と同じ形のルート(R1)に本物の業務処理で申請・処理して作る。名前は表示名(案件の操作/README.md 1.3)。
 */
final class CaseDiagramTest extends TransactionalTestCase
{
    use OperatesOnRoutes;

    /**
     * ノードの状態 → 札・表の語(認可と一覧.md 2 節: 処理済み・通過 = 処理済み、承認待ち・展開中 = 承認待ち、再申請待ち、到達していない、省略。
     * 保留中は詳細だけ state が hold = 案件の運用/画面/案件の画面.md の 4 節)
     */
    private const STATE_LABELS = [
        'done' => ['done', '処理済み'], 'passed' => ['done', '処理済み'], 'waiting' => ['current', '承認待ち'], 'running' => ['current', '承認待ち'],
        'reapply' => ['returned', '再申請待ち'], 'unreached' => ['upcoming', '到達していない'], 'skipped' => ['skipped', '省略'],
        'hold' => ['hold', '保留中'],
    ];

    /**
     * WF-000009(合議の途中)を合議の子 2 の処理対象者 u0010 が見る: 部長を通った枝、合議の子 3 つ、責任者は到達していない。人は処理済み = 処理者・現在 = 処理対象者
     * (自分 = u0010)。縦配置の短い文と完了の条件、指定されるノードの短い文。
     */
    public function test_the_nodes_of_a_council_in_progress_take_the_diagram_shape(): void
    {
        $detail = $this->detailOf(9, 'u0010');

        $this->assertSame([
            ['申請', 'apply', null, null, 'done', ['u0001 0'], 'apply', null, null],
            ['課長', 'approve', null, null, 'done', ['u0002 0'], 'approve', null, null],
            ['予算', 'branch_start', null, null, 'passed', [], null, null, null],
            ['部長', 'approve', null, null, 'done', ['u0003 0'], 'approve', null, null],
            ['分岐終了', 'branch_end', null, null, 'passed', [], null, null, null],
            ['関係部署の合議', 'parallel', null, null, 'running', [], null, '処理担当者は課長が指定', '完了の条件 承認 2 人　現在 1 人'],
            ['関係部署の合議 承認 1', 'approve', '関係部署の合議', 1, 'done', ['u0009 0'], 'approve', null, null],
            ['関係部署の合議 承認 2', 'approve', '関係部署の合議', 2, 'waiting', ['u0010 1'], null, null, null],
            ['関係部署の合議 承認 3', 'approve', '関係部署の合議', 3, 'waiting', ['u0016 0'], null, null, null],
            ['責任者', 'approve', null, null, 'unreached', [], null, '課長が指定', null],
        ], $this->compactNodes($detail['nodes']));
        $this->assertSame(['apply', 'section_chief', 'budget', 'manager', 'budget_end', 'council', null, null, null, 'owner'], array_column($detail['nodes'], 'code'));
        $this->assertSame([[]], array_values(array_unique(array_column($detail['nodes'], 'placeholders'), SORT_REGULAR)));
        $this->assertSame([false], array_values(array_unique(array_column($detail['nodes'], 'awaitingDesignation'))));
        $this->assertSame([true], array_values(array_unique(array_map(fn (array $node) => is_string($node['processed']['at']), array_filter($detail['nodes'], fn (array $node) => $node['processed'] !== null)))));
    }

    /**
     * 辺は写した辺(並び順)で、分岐開始から出る辺だけが到達の時の評価を持つ: 予算 350 = 部長の枝が真・「何もしない」の枝が偽(通らなかった枝)。WF-000010(予算 120)は逆。
     */
    public function test_the_edges_carry_the_branch_evaluation(): void
    {
        $this->assertSame([
            ['申請', '課長', null, null], ['課長', '予算', null, null], ['予算', '部長', '予算 300 万円以上', true], ['予算', '分岐終了', '何もしない', false],
            ['部長', '分岐終了', null, null], ['分岐終了', '関係部署の合議', null, null], ['関係部署の合議', '責任者', null, null],
        ], $this->compactEdges($this->detailOf(9, 'u0010')));
        $this->assertSame([[true, false], [false, true], [true, false], [false, true]], array_map(
            fn (int $number) => array_column(array_filter($this->compactEdges($this->detailOf($number, 'u0001')), fn (array $edge) => $edge[0] === '予算'), 3),
            [9, 10, 11, 12],
        ));
    }

    /**
     * 合議の進み具合(画面/ホームと意匠とE2E.md 7 節・案件の操作 2.5): WF-000009 = 1 人が承認・必要 2 人・未処理 2、子ごとの行(人・所属 = 申請基準日の主所属・処理日時・状態の語・自分)。
     */
    public function test_the_council_in_progress_counts_approvals_and_lists_each_child(): void
    {
        $councils = $this->detailOf(9, 'u0010')['councils'];

        $this->assertSame([['関係部署の合議', 1, 2, 2, false]], array_map(fn (array $council) => [$council['name'], $council['approved'], $council['required'], $council['pending'], $council['passed']], $councils));
        $this->assertSame([
            ['関係部署の合議 承認 1', 'u0009', false, 'general-affairs', true, ['done', '処理済み'], 'approve'],
            ['関係部署の合議 承認 2', 'u0010', true, 'accounting', false, ['current', '承認待ち'], null],
            ['関係部署の合議 承認 3', 'u0016', false, 'sales-2', false, ['current', '承認待ち'], null],
        ], array_map(fn (array $row) => [$row['name'], $row['person']['code'] ?? null, $row['person']['isMe'] ?? null, $row['department']['code'] ?? null, $row['at'] !== null, array_values($row['stepState']), $row['action']], $councils[0]['rows']));
        $this->assertSame($this->nodeIdOf($this->detailOf(9, 'u0010'), '関係部署の合議'), $councils[0]['nodeId']);
    }

    /**
     * WF-000012(可決の後): 部長は省略(通らなかった枝)、合議は通過で 2 人が承認・必要 2 人・未処理 0、責任者は処理済み。WF-000010: 部長は省略、合議は展開中で 0 人が承認。
     */
    public function test_a_passed_council_and_a_skipped_manager(): void
    {
        $twelfth = $this->detailOf(12, 'u0001');
        $tenth = $this->detailOf(10, 'u0001');

        $this->assertSame(
            [['skipped', ['skipped', '省略']], ['passed', ['done', '処理済み']], ['done', ['done', '処理済み']]],
            array_map(fn (string $name) => $this->stateOf($twelfth, $name), ['部長', '関係部署の合議', '責任者']),
        );
        $this->assertSame([['関係部署の合議', 2, 2, 0, true, ['done u0009', 'done u0010']]], $this->compactCouncils($twelfth));
        $this->assertSame('完了の条件 承認 2 人　現在 2 人', $this->nodeOf($twelfth, '関係部署の合議')['consensus']['text'] ?? null);
        $this->assertSame([['skipped', ['skipped', '省略']], ['running', ['current', '承認待ち']]], array_map(fn (string $name) => $this->stateOf($tenth, $name), ['部長', '関係部署の合議']));
        $this->assertSame([['関係部署の合議', 0, 2, 2, false, ['current u0009', 'current u0010']]], $this->compactCouncils($tenth));
    }

    /**
     * WF-000011(合議が可決できなくなって否認で完了): 縦配置は省略なので進み具合を出さず、完了の条件は定義の条件。否認の子は処理済み(種別 = 否認)、u0016 の子は省略。
     */
    public function test_a_failed_council_has_no_progress_and_shows_the_rejections(): void
    {
        $detail = $this->detailOf(11, 'u0001');

        $this->assertSame([], $detail['councils']);
        $this->assertSame('完了の条件 承認 2 人', $this->nodeOf($detail, '関係部署の合議')['consensus']['text'] ?? null);
        $this->assertSame(
            [['skipped', ['skipped', '省略'], null], ['done', ['done', '処理済み'], 'reject'], ['done', ['done', '処理済み'], 'reject'], ['skipped', ['skipped', '省略'], null], ['unreached', ['upcoming', '到達していない'], null]],
            array_map(fn (string $name) => [...$this->stateOf($detail, $name), $this->nodeOf($detail, $name)['processed']['action'] ?? null], ['関係部署の合議', '関係部署の合議 承認 1', '関係部署の合議 承認 2', '関係部署の合議 承認 3', '責任者']),
        );
    }

    /**
     * 図の札・表の状態の語は写しの表(認可と一覧.md 2 節。保留中の state は詳細だけ hold = 案件の画面.md の 4 節)のとおり:
     * 16 件の見本のすべてのノードで、状態ごとに同じ語。
     */
    public function test_every_node_state_reads_the_one_label_table(): void
    {
        $seen = [];

        foreach (WorkflowCase::query()->whereNotNull('case_number')->orderBy('case_number')->get() as $case) {
            foreach ($this->detailOf((int) $case->case_number, User::query()->findOrFail($case->user_id)->code)['nodes'] as $node) {
                $this->assertSame(self::STATE_LABELS[$node['state']], array_values($node['stepState']), "WF {$case->case_number} の {$node['name']}");
                $seen[$node['state']] = true;
            }
        }

        $this->assertEqualsCanonicalizing(['done', 'passed', 'waiting', 'running', 'reapply', 'unreached', 'skipped', 'hold'], array_keys($seen));
    }

    /**
     * 否認の票(画面/ホームと意匠とE2E.md 7 節・区切り 4-2 の修正 #2): 合議の途中で子 1 が否認すると、進み具合の行と図の子の箱が処理の種別(否認)を持ち、否認は承認に数えない。
     * 否認した本人の行と箱だけが自分。
     */
    public function test_a_rejected_vote_carries_its_action_and_is_not_counted_as_an_approval(): void
    {
        $case = $this->councilCase(['u0004', 'u0005', 'u0009']);
        $this->reject($case, 'council.1', 'u0004');

        $detail = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0004')), $case->id);

        $this->assertSame([['関係部署の合議', 0, 2, 2, false, ['done u0004', 'current u0005', 'current u0009']]], $this->compactCouncils($detail));
        $this->assertSame([['reject', true], [null, false], [null, false]], array_map(fn (array $row) => [$row['action'], $row['person']['isMe'] ?? null], $detail['councils'][0]['rows']));
        $this->assertSame(['reject', [['u0004', true]]], [
            $this->nodeOf($detail, '関係部署の合議 承認 1')['processed']['action'] ?? null,
            array_map(fn (array $person) => [$person['code'], $person['isMe']], $this->nodeOf($detail, '関係部署の合議 承認 1')['people']),
        ]);
    }

    /**
     * 可決の後に省いた子の行(修正 #9): 人は今の指定の同じ番号の人のまま出すが、その人が見ても自分にしない(現在・処理済みの行だけが自分)。
     * u0010 は計画のフローの参照者(経理部)なので、省かれた後も詳細を見られる。
     */
    public function test_a_skipped_row_keeps_its_person_but_is_never_mine(): void
    {
        $case = $this->councilCase(['u0004', 'u0005', 'u0010']);
        $this->approve($case, 'council.1', 'u0004');
        $this->approve($case, 'council.2', 'u0005');

        $detail = app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0010')), $case->id);

        $this->assertSame([['関係部署の合議', 2, 2, 0, true, ['done u0004', 'done u0005', 'skipped u0010']]], $this->compactCouncils($detail));
        $this->assertSame([false, false, false], array_map(fn (array $row) => $row['person']['isMe'] ?? null, $detail['councils'][0]['rows']));
    }

    /**
     * 指定の前の枠(修正 #8・#31、画面/ホームと意匠とE2E.md 8 節): 処理の時に指定するノードは、今の指定が無い && 到達していない && 案件が未完了のときだけ awaitingDesignation。
     * 申請の直後(課長がまだ指定していない)= 合議・責任者が真、指定した後 = 偽、指定せずに承認終了で完了した案件 = 偽。ほかのノードはいつも偽。
     */
    public function test_awaiting_designation_only_before_the_designation_of_an_active_case(): void
    {
        $this->addVersion($this->r1());
        [$applied] = $this->applyOnRoute(['budget' => 100]);
        [$early] = $this->applyOnRoute(['budget' => 100]);
        $this->operateOn(ApproveCaseEarly::class, $early, 'section_chief', 'u0002');
        $awaiting = fn (WorkflowCase $case) => array_keys(array_filter(array_column(app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0001')), $case->id)['nodes'], 'awaitingDesignation', 'name')));

        $this->assertSame(['関係部署の合議', '責任者'], $awaiting($applied));
        $this->assertSame([], $awaiting($early));
        $this->approve($applied, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => ['u0011']]);
        $this->assertSame([], $awaiting($applied));
    }

    /**
     * 0 人を指定した後(修正 #31): 指定するノードが 0 人を指定した(今の指定の行が 0 件)ノードは、到達していなくても awaitingDesignation でない
     * (前のステップが指定済み)。責任者の人数の最小を 0 にした R1 で、課長が合議に 2 人・責任者に 0 人を指定する(責任者は合議の後なので到達していないまま)。
     */
    public function test_a_node_designated_with_nobody_is_not_awaiting_designation(): void
    {
        [$nodes, $edges] = $this->r1();
        $nodes = array_map(fn (RouteNodeInput $node) => $node->code === 'owner'
            ? new RouteNodeInput('owner', '責任者', WorkflowNodeKind::Approve, WorkflowTargetKind::Designated, designatorCode: 'section_chief', minAssignees: 0, maxAssignees: 1, preventsApplicantApproval: true, sendBackCodes: ['apply', 'section_chief'])
            : $node, $nodes);
        $this->addVersion([$nodes, $edges]);
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => ['u0004', 'u0005'], 'owner' => []]);

        $owner = $this->nodeOf(app(CaseDetailQuery::class)->show($this->contextOf($this->seededUser('u0001')), $case->id), '責任者');

        $this->assertSame(['unreached', false], [$owner['state'], $owner['awaitingDesignation']]);
    }

    /**
     * R1(版 3 と同じ形)に予算 100 で申請し、課長が合議と責任者(u0011)を指定して承認した案件(部長は通らない枝)。
     *
     * @param  list<string>  $council  合議の人のコード
     */
    private function councilCase(array $council): WorkflowCase
    {
        $this->addVersion($this->r1());
        [$case] = $this->applyOnRoute(['budget' => 100]);
        $this->approve($case, 'section_chief', 'u0002', ['council' => $council, 'owner' => ['u0011']]);

        return $case;
    }

    /**
     * @return array<string, mixed>
     */
    private function detailOf(int $number, string $viewer): array
    {
        $user = User::query()->where('code', $viewer)->sole();
        $context = new AccessContext($user->id, null, null, CarbonImmutable::today(AccessContext::TIMEZONE), AccessContext::LOCALE_CODE);

        return app(CaseDetailQuery::class)->show($context, WorkflowCase::query()->where('case_number', $number)->sole()->id);
    }

    /**
     * ノードの [名前, 種類, 親の名前, 番号, 状態, 人の「コード 自分」, 処理の種別, 短い文, 完了の条件]。
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<list<mixed>>
     */
    private function compactNodes(array $nodes): array
    {
        $names = array_column($nodes, 'name', 'id');

        return array_map(fn (array $node) => [
            $node['name'], $node['kind'], $node['parentId'] === null ? null : $names[$node['parentId']], $node['sequence'], $node['state'],
            array_map(fn (array $person) => $person['code'].' '.($person['isMe'] ? '1' : '0'), $node['people']), $node['processed']['action'] ?? null, $node['subtitle'], $node['consensus']['text'] ?? null,
        ], $nodes);
    }

    /**
     * 辺の [出るノード, 入るノード, 名前, taken]。
     *
     * @param  array<string, mixed>  $detail
     * @return list<list<mixed>>
     */
    private function compactEdges(array $detail): array
    {
        $names = array_column($detail['nodes'], 'name', 'id');

        return array_values(array_map(fn (array $edge) => [$names[$edge['from']], $names[$edge['to']], $edge['label'], $edge['taken']], $detail['edges']));
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return list<list<mixed>>
     */
    private function compactCouncils(array $detail): array
    {
        return array_values(array_map(fn (array $council) => [
            $council['name'], $council['approved'], $council['required'], $council['pending'], $council['passed'],
            array_map(fn (array $row) => "{$row['stepState']['state']} {$row['person']['code']}", $council['rows']),
        ], $detail['councils']));
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function nodeOf(array $detail, string $name): array
    {
        $matches = array_values(array_filter($detail['nodes'], fn (array $node) => $node['name'] === $name));
        $this->assertCount(1, $matches, $name);

        return $matches[0];
    }

    /**
     * @param  array<string, mixed>  $detail
     */
    private function nodeIdOf(array $detail, string $name): int
    {
        return $this->nodeOf($detail, $name)['id'];
    }

    /**
     * @param  array<string, mixed>  $detail
     * @return array{string, list<string>}
     */
    private function stateOf(array $detail, string $name): array
    {
        $node = $this->nodeOf($detail, $name);

        return [$node['state'], array_values($node['stepState'])];
    }
}
