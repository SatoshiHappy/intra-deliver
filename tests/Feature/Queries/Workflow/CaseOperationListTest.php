<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Enums\Workflow\WorkflowFlowAction;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseOperationList;
use Carbon\CarbonImmutable;
use Tests\Support\GrantsFlowPermissions;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 申請操作の一覧(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.3 節・6 節、形 = 画面/管理と通知の画面.md の 7.1 節): 出る案件 = 管理の述語
 * (read を許されるフローの一時保存でない案件)、絞り込み(scope × overdue の表・flow・q)、並びとページ送り、列。期待は文書の表と文から書いた。
 * 今日 = 2026-10-13。テストのフロー wf_plan の案件で比べる(初期データの案件は flow で外す)。管理者 = u0004(初期データでどのフローも read を許される)。
 */
final class CaseOperationListTest extends TransactionalTestCase
{
    use GrantsFlowPermissions;
    use ReadsCaseLists;

    private const MANAGER = 'u0004';

    /**
     * scope × overdue の表の 3 行。期限 3 日の a1 で、到達した日(Asia/Tokyo)が 10-09(期限 10-12 = 超過)・10-10(期限 10-13 = 当日で超過でない。
     * UTC では 10-09)・10-01(超過)の未完了と、承認で完了した案件。解釈できない scope は未完了、completed は overdue を読まない。
     * 絞った行の期限超過の札(現在のノードの全部で求める)が絞り込みと一致する。
     */
    public function test_the_scope_and_the_overdue_filter(): void
    {
        $this->addLine($this->listNode('a1', ['u0005'], 3));
        $boundary = $this->applyAt('2026-10-09 03:00:00');
        $notYet = $this->applyAt('2026-10-09 15:30:00');
        $old = $this->applyAt('2026-10-01 03:00:00');
        $done = $this->applyAt('2026-10-02 03:00:00');
        $this->travelTo($this->operationDay()->setTime(12, 0));
        $this->operate(ApproveCase::class, $this->seededUser('u0005'), $done);

        $overdue = $this->listAs(self::MANAGER, ['flow' => self::FLOW_CODE, 'overdue' => '1']);
        $active = $this->listAs(self::MANAGER, ['flow' => self::FLOW_CODE, 'scope' => 'unknown', 'overdue' => '0']);
        $completedQuery = ['flow' => self::FLOW_CODE, 'scope' => 'completed', 'overdue' => '1'];
        $completed = $this->listAs(self::MANAGER, $completedQuery);

        $this->assertSame([$boundary->id, $old->id], $this->idsOf($overdue));
        $this->assertSame([true, true], array_column(array_column($overdue['cases']['data'], 'deadline'), 'overdue'));
        $this->assertSame([$notYet->id, $boundary->id, $old->id], $this->idsOf($active));
        $this->assertSame('2026-10-13', $this->deadlineOf($active, $notYet)['dueOn']);
        $this->assertFalse($this->deadlineOf($active, $notYet)['overdue']);
        $this->assertSame([$done->id], $this->idsOf($completed));
        $query = ['flow' => self::FLOW_CODE, 'scope' => 'active', 'q' => '', 'overdue' => false];
        $this->assertSame([$query, $query], [$active['query'], [...$overdue['query'], 'overdue' => false]]);
        $this->assertTrue($overdue['query']['overdue']);
        $this->assertSame([...$query, 'scope' => 'completed'], $completed['query']);
    }

    /**
     * flow: read を許されるフローのコードだけを受け、無い・解釈できない・read の無いフローは既定 = すべて。選択肢 = read を許されるフロー(並び =
     * フローの一覧)。read を wf_plan だけに許された u0005 には wf_plan の案件だけが出て、read の無い u0006 には何も出ない(テストの中でポリシーを足す)。
     */
    public function test_the_flows_are_those_readable(): void
    {
        $this->addLine($this->listNode('a1', ['u0007']));
        $case = $this->applyAt('2026-10-12 03:00:00');
        $this->grantFlow($this->seededUser('u0005'), self::FLOW_CODE, WorkflowFlowAction::Read);
        $everyFlow = array_values(WorkflowFlow::query()->orderBy('sort_key')->orderBy('code')->get()
            ->map(fn (WorkflowFlow $flow) => ['code' => $flow->code, 'name' => $flow->name])->all());

        $all = $this->listAs(self::MANAGER, []);
        $byGarbage = $this->listAs(self::MANAGER, ['flow' => ['x']]);
        $own = $this->listAs('u0005', ['flow' => 'project_plan']);
        $none = $this->listAs('u0006', []);

        $this->assertSame([$everyFlow, null], [$all['flows'], $all['query']['flow']]);
        $this->assertContains($case->id, $this->idsOf($all));
        $this->assertGreaterThan(1, count(array_unique(array_column($all['cases']['data'], 'flowName'))));
        $this->assertSame([$all['cases']['total'], null], [$byGarbage['cases']['total'], $byGarbage['query']['flow']]);
        $planFlow = ['code' => self::FLOW_CODE, 'name' => 'プロジェクト計画の承認'];
        $this->assertSame([[$planFlow], null, [$case->id]], [$own['flows'], $own['query']['flow'], $this->idsOf($own)]);
        $this->assertSame([[], 0], [$none['flows'], $none['cases']['total']]);
    }

    /**
     * 一時保存は出ない(管理の述語)。q = 申請一覧の検索の語の規則(申請名・申請番号)。
     */
    public function test_drafts_are_not_listed_and_the_keyword_filters(): void
    {
        $this->addLine($this->listNode('a1', ['u0007']));
        $special = $this->applyAt('2026-10-12 03:00:00', '特別な申請');
        $plain = $this->applyAt('2026-10-12 04:00:00', '普通の申請');
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;
        $owner = $this->seededUser('u0004')->id;
        $draft = WorkflowCase::factory()->draft()->createOne(['workflow_flow_id' => $flowId, 'user_id' => $owner]);

        $listed = $this->idsOf($this->listAs(self::MANAGER, ['flow' => self::FLOW_CODE]));
        $byName = $this->listAs(self::MANAGER, ['flow' => self::FLOW_CODE, 'q' => ' 特別 ']);
        $number = $this->listAs(self::MANAGER, ['q' => $this->rowOfList($byName, $special)['number']]);

        $this->assertSame([$plain->id, $special->id], $listed);
        $this->assertNotContains($draft->id, $listed);
        $this->assertSame([[$special->id], '特別'], [$this->idsOf($byName), $byName['query']['q']]);
        $this->assertSame([$special->id], $this->idsOf($number));
    }

    /**
     * 並び = updated_at の降順・id の降順、20 件ずつ(21 件で 2 ページ。解釈できない・大きすぎる page は 1・最後のページ)。
     */
    public function test_the_order_and_the_pages(): void
    {
        $this->addLine($this->listNode('a1', ['u0007']));
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;
        $applicant = $this->seededUser(self::APPLICANT)->id;
        $this->travelTo(CarbonImmutable::parse('2026-10-12 03:00:00'));
        $cases = WorkflowCase::factory()->active()->count(21)
            ->create(['workflow_flow_id' => $flowId, 'user_id' => $applicant]);
        $expected = array_reverse($cases->modelKeys());
        $first = $this->listAs(self::MANAGER, ['flow' => self::FLOW_CODE, 'page' => 'x']);
        $second = $this->listAs(self::MANAGER, ['flow' => self::FLOW_CODE, 'page' => '9']);

        $this->assertSame([array_slice($expected, 0, 20), 1, 2, 21], [$this->idsOf($first), ...$this->pageOf($first)]);
        $this->assertSame([array_slice($expected, 20), 2, 2, 21], [$this->idsOf($second), ...$this->pageOf($second)]);
    }

    /**
     * 列(7.1 節): 申請名・番号・フロー名・申請者・状態の札・現在のステップ(保留中の札のための状態)・経過日数・期限。経過日数・期限の対象は
     * 現在のノードの全部(管理者は処理担当者でないが値を持つ)。
     */
    public function test_the_columns_of_a_row(): void
    {
        $this->addLine($this->listNode('a1', ['u0007'], 2));
        $case = $this->applyAt('2026-10-10 03:00:00', '列の見本');
        $this->holdOn($case, 'a1', 'u0007');

        $row = $this->rowOfList($this->listAs(self::MANAGER, ['flow' => self::FLOW_CODE]), $case);

        $applicant = $this->seededUser(self::APPLICANT);
        $named = [$row['name'], $row['flowName'], $row['applicant']['id']];
        $this->assertSame(['列の見本', 'プロジェクト計画の承認', $applicant->id], $named);
        $this->assertMatchesRegularExpression('/^WF-[0-9]{6,}\z/', (string) $row['number']);
        $this->assertSame(['state' => 'hold', 'name' => 'a1'], $row['currentSummary']);
        $plain = ['parentId' => null, 'parentName' => null, 'childName' => null];
        $current = [['id' => $this->caseNode($case, 'a1')->id, 'name' => 'a1', ...$plain, 'state' => 'hold']];
        $this->assertSame($current, $row['currentNodes']);
        $deadline = ['reachedOn' => '2026-10-10', 'elapsedDays' => 3, 'dueOn' => '2026-10-12', 'overdue' => true];
        $this->assertSame($deadline, $row['deadline']);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function listAs(string $userCode, array $query): array
    {
        $context = $this->contextOf($this->seededUser($userCode));

        return app(CaseOperationList::class)->list(
            $context,
            $query['flow'] ?? null,
            $query['scope'] ?? null,
            $query['q'] ?? null,
            $query['overdue'] ?? null,
            $query['page'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $list
     * @return list<int>
     */
    private function idsOf(array $list): array
    {
        return array_column($list['cases']['data'], 'id');
    }

    /**
     * @param  array<string, mixed>  $list
     * @return list<int>
     */
    private function pageOf(array $list): array
    {
        return [$list['cases']['currentPage'], $list['cases']['lastPage'], $list['cases']['total']];
    }

    /**
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>
     */
    private function rowOfList(array $list, WorkflowCase $case): array
    {
        $rows = array_column($list['cases']['data'], null, 'id');
        $this->assertArrayHasKey($case->id, $rows);

        return $rows[$case->id];
    }

    /**
     * @param  array<string, mixed>  $list
     * @return array<string, mixed>
     */
    private function deadlineOf(array $list, WorkflowCase $case): array
    {
        return $this->rowOfList($list, $case)['deadline'];
    }
}
