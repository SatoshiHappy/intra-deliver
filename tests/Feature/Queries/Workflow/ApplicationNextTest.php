<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\CreateDraft;
use App\Actions\Workflow\DraftInput;
use App\Actions\Workflow\RouteEdgeInput;
use App\Actions\Workflow\RouteNodeInput;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowTargetKind;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\ApplicationFormQuery;
use App\Queries\Workflow\ApplicationNext;
use App\Queries\Workflow\WorkflowPeople;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkflowSeeder;
use Tests\Support\BuildsRouteVersions;
use Tests\Support\TransactionalTestCase;

/**
 * 申請の画面の次の処理対象者と申請基準日(docs の 設計/ワークフロー/ルートの深さ/画面/案件の側の画面.md 5 節の next・baseDateRange・baseDate、案件の操作/操作.md 10.1)。
 * 申請基準日の版で、申請のノードから分岐開始を評価せずに届くノード(同期開始なら複数)を展開する。初期データの計画のフロー(版 1 = 無効・版 2 = 一本道 A・版 3 = 分岐 B)
 * と、テストのフローに足した版を使う。
 */
final class ApplicationNextTest extends TransactionalTestCase
{
    use BuildsRouteVersions;

    /**
     * 計画のフロー: 版 2 の日は一本道 A の課長の承認(u0002)、版 3 の日は分岐 B の課長(申請者 u0001 の所属組織 + 課長 = u0002)、無効の版 1 の日は版が無い(version null)。
     * 担当組織はどの日も申請者の所属組織。
     */
    public function test_the_next_steps_follow_the_version_of_the_base_date(): void
    {
        $flowId = WorkflowFlow::query()->where('code', 'project_plan')->sole()->id;
        $u0002 = $this->person('u0002');

        $this->assertSame([
            ['version' => 2, 'noVersionMessage' => null, 'departmentName' => '東京営業課', 'nodes' => [['name' => '課長の承認', 'targets' => [$u0002], 'blockedReason' => null]], 'dependsOnContent' => false],
            ['version' => 3, 'noVersionMessage' => null, 'departmentName' => '東京営業課', 'nodes' => [['name' => '課長', 'targets' => [$u0002], 'blockedReason' => null]], 'dependsOnContent' => false],
            ['version' => null, 'noVersionMessage' => '申請基準日 2025-06-01 に申請できる版がありません', 'departmentName' => '東京営業課', 'nodes' => [], 'dependsOnContent' => false],
        ], array_map(fn (string $date) => $this->ofFlow($flowId, 'u0001', $date), [WorkflowSeeder::DATES['stage2_plan_base_date'], '2026-10-13', '2025-06-01']));
    }

    /**
     * 次が同期開始なら枝ごとの最初のノード(写した並び)、分岐開始なら到達するノードは内容で決まる(dependsOnContent = 真、nodes は分岐の前まで = 空)。
     */
    public function test_a_sync_start_lists_each_branch_and_a_branch_start_depends_on_the_content(): void
    {
        $this->addVersion($this->r2());
        $this->addVersion([[$this->applyNode(),
            new RouteNodeInput('budget', '予算', WorkflowNodeKind::BranchStart, closingCode: 'budget_end'),
            $this->approveNode('big', 'u0003', ['apply']),
            new RouteNodeInput('budget_end', '分岐終了', WorkflowNodeKind::BranchEnd),
            $this->approveNode('final', 'u0010', ['apply']),
        ], [
            new RouteEdgeInput('apply', 'budget'),
            new RouteEdgeInput('budget', 'big', 1, '大きい', ['all' => [['field' => 'budget', 'op' => 'ge', 'value' => 300]]]),
            new RouteEdgeInput('budget', 'budget_end', 2, '小さい', ['all' => [['field' => 'budget', 'op' => 'lt', 'value' => 300]]]),
            new RouteEdgeInput('big', 'budget_end'),
            new RouteEdgeInput('budget_end', 'final'),
        ]], '2026-09-01');
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;
        $of = fn (string $date) => $this->ofFlow($flowId, 'u0001', $date);

        $this->assertSame([[['a1', ['u0002']], ['b1', ['u0004']]], false], $this->summary($of('2026-06-01')));
        $this->assertSame([[], true], $this->summary($of('2026-10-13')));
    }

    /**
     * 先読みは同期終了・分岐終了で止まる(申請の時点で区間は済んでいない): 申請 → 同期開始 →(a1 / 直接 同期終了)→ final の版で、next は a1 だけ(final は入らない)。
     */
    public function test_the_lookahead_stops_at_the_sync_end(): void
    {
        $this->addVersion([[$this->applyNode(),
            new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'),
            $this->approveNode('a1', 'u0002', ['apply']),
            new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd),
            $this->approveNode('final', 'u0010', ['apply', 'a1']),
        ], $this->chain(['apply', 'sync'], ['sync', 'a1'], ['sync', 'sync_end'], ['a1', 'sync_end'], ['sync_end', 'final'])]);
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;

        $this->assertSame([[['a1', ['u0002']]], false], $this->summary($this->ofFlow($flowId, 'u0001', '2026-10-13')));
    }

    /**
     * 申請者承認防止のノードの今の展開が申請者本人だけなら blockedReason に 13 節の文言(画面/案件の側の画面.md 5 節)。
     * 申請者とほかの人がいる・0 人(設計/ワークフロー/画面/申請と案件.md 3 節の文で示す)・
     * 防止がオフなら null。並びは位置の順(同期の枝)。
     */
    public function test_a_preventing_node_whose_only_target_is_the_applicant_has_the_blocked_reason(): void
    {
        $sales = new RouteNodeInput('sales', '東京営業課', WorkflowNodeKind::Approve, WorkflowTargetKind::Fixed, [new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('tokyo-sales'))], ['ja' => '東京営業課'], preventsApplicantApproval: true, sendBackCodes: ['apply']);
        $this->addVersion([[$this->applyNode(),
            new RouteNodeInput('sync', '同期', WorkflowNodeKind::SyncStart, closingCode: 'sync_end'),
            $this->approveNode('only', 'u0001', ['apply'], prevents: true), $sales, $this->approveNode('nobody', 'u0015', ['apply'], prevents: true), $this->approveNode('free', 'u0001', ['apply']),
            new RouteNodeInput('sync_end', '同期終了', WorkflowNodeKind::SyncEnd),
        ], $this->chain(['apply', 'sync'], ['sync', 'free'], ['sync', 'nobody'], ['sync', 'sales'], ['sync', 'only'], ['only', 'sync_end'], ['sales', 'sync_end'], ['nobody', 'sync_end'], ['free', 'sync_end'])]);
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;

        $this->assertSame([
            ['only', ['u0001'], 'onlyの処理担当者が申請者本人だけのため、この操作はできません。このステップには、申請者承認防止が設定されています。'],
            ['東京営業課', ['u0001', 'u0002'], null],
            ['nobody', [], null],
            ['free', ['u0001'], null],
        ], array_map(fn (array $node) => [$node['name'], array_column($node['targets'], 'code'), $node['blockedReason']], $this->ofFlow($flowId, 'u0001', '2026-10-13')['nodes']));
    }

    /**
     * 選べる範囲: 最小 = 有効な版の最初の開始日(無効の版 1 を除く)と今日の 1 年前の遅い方、最大 = 有効な版の最後の日(最後の版は終わりなし)と今日の 1 年後の早い方。
     * 今日が 2 月 29 日なら 1 年前・1 年後は 2 月 28 日(設計/ワークフロー/ルートの深さ/案件の操作/操作.md 10.1)。
     */
    public function test_the_base_date_range_is_within_the_enabled_versions_and_a_year_around_today(): void
    {
        $flowId = WorkflowFlow::query()->where('code', 'project_plan')->sole()->id;

        $this->assertSame(['min' => '2026-04-01', 'max' => '2027-10-13'], $this->next()->baseDateRange($flowId, CarbonImmutable::parse('2026-10-13')));
        $this->assertSame(['min' => '2027-02-28', 'max' => '2029-02-28'], $this->next()->baseDateRange($flowId, CarbonImmutable::parse('2028-02-29')));
    }

    /**
     * 一時保存の画面の申請基準日の初期値 = 一時保存の値(クエリ base_date があればそちら、読めない値は飛ばして今日)。next はその日の版。
     */
    public function test_the_draft_form_starts_from_the_saved_base_date(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draftId = app(CreateDraft::class)->execute($this->contextOf($u0001), 'project_plan', new DraftInput(null, null, [], [], WorkflowSeeder::DATES['stage2_plan_base_date']));
        $form = fn (mixed $query) => app(ApplicationFormQuery::class)->draft($this->contextOf($u0001), $draftId, $query) ?? $this->fail('一時保存の画面が null');

        $this->assertSame([WorkflowSeeder::DATES['stage2_plan_base_date'], 2], [$form(null)['baseDate'], $form(null)['next']['version']]);
        $this->assertSame(['2026-10-13', 3], [$form('2026-10-13')['baseDate'], $form('2026-10-13')['next']['version']]);
        $this->assertSame([WorkflowSeeder::DATES['stage2_plan_base_date'], 2], [$form('2026-02-30')['baseDate'], $form('x')['next']['version']]);
    }

    /**
     * 申請基準日の初期値の候補(クエリ・一時保存の値の順)は、日付として読めれば今日の前後 1 年の外でもそのまま欄と next に使い(範囲は送った後の 422 で示す)、
     * 読めなければ次の候補、どれも読めなければ今日(設計/ワークフロー/画面/申請と案件.md 5 節)。今日 = 2026-10-13:
     * (a) クエリの 2027-10-14(範囲の外。版 3 は終わりなし)→ その日と版 3 (b) 一時保存の 2025-06-01(範囲の外。無効の版 1 の日)→ その日と版なし(画面は「版がありません」)
     * (c) 読めないクエリ → 一時保存の値、読めない一時保存の値 → 今日。
     */
    public function test_the_initial_base_date_keeps_a_readable_candidate_even_outside_a_year_around_today(): void
    {
        $u0001 = $this->seededUser('u0001');
        $draftOf = fn (string $saved) => app(CreateDraft::class)->execute($this->contextOf($u0001), 'project_plan', new DraftInput(null, null, [], [], $saved));
        $inRange = $draftOf(WorkflowSeeder::DATES['stage2_plan_base_date']);
        $outOfRange = $draftOf('2025-06-01');
        $unreadable = $draftOf('2025-02-30');
        $of = function (int $draftId, ?string $query): array {
            $form = app(ApplicationFormQuery::class)->draft($this->contextOf($this->seededUser('u0001')), $draftId, $query) ?? $this->fail('一時保存の画面が null');

            return [$form['baseDate'], $form['next']['version']];
        };
        $new = app(ApplicationFormQuery::class)->create($this->contextOf($u0001), 'project_plan', '2027-10-14');

        $this->assertSame(['2027-10-14', 3], [$new['baseDate'], $new['next']['version']]);
        $this->assertSame([
            ['2027-10-14', 3],
            ['2025-06-01', null],
            [WorkflowSeeder::DATES['stage2_plan_base_date'], 2],
            ['2026-10-13', 3],
            ['2025-10-12', null],
        ], [$of($inRange, '2027-10-14'), $of($outOfRange, null), $of($inRange, '2026-02-30'), $of($unreadable, null), $of($inRange, '2025-10-12')]);
    }

    /**
     * @param  array{version: int|null, noVersionMessage: string|null, departmentName: string|null, nodes: list<array{name: string, targets: list<array{id: int, code: string, name: string|null}>, blockedReason: string|null}>, dependsOnContent: bool}  $next
     * @return array{list<array{string, list<string>}>, bool}
     */
    private function summary(array $next): array
    {
        return [array_map(fn (array $node) => [$node['name'], array_column($node['targets'], 'code')], $next['nodes']), $next['dependsOnContent']];
    }

    /**
     * @return array{id: int, code: string, name: string|null}
     */
    private function person(string $code): array
    {
        $id = $this->seededUser($code)->id;

        return app(WorkflowPeople::class)->users([$id], CarbonImmutable::parse('2026-10-13'), 'ja')[$id];
    }

    /**
     * @return array{version: int|null, noVersionMessage: string|null, departmentName: string|null, nodes: list<array{name: string, targets: list<array{id: int, code: string, name: string|null}>, blockedReason: string|null}>, dependsOnContent: bool}
     */
    private function ofFlow(int $flowId, string $applicantCode, string $date): array
    {
        return $this->next()->ofFlow($this->contextOf($this->seededUser($applicantCode)), $flowId, CarbonImmutable::parse($date));
    }

    private function next(): ApplicationNext
    {
        return app(ApplicationNext::class);
    }
}
