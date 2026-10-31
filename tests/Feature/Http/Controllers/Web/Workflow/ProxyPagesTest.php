<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Master\Department;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowProxy;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\RegistersProxies;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 代理の設定・代理の管理のページの props(docs の 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md の 7.3 節・7.4 節): 自分の 2 つの表に入る行、
 * 行の形(person の所属は今日に有効な人の主所属、endDate = 最後の有効な日、flowName が null = すべてのフロー)、並び(状態 → 開始日の降順 → id の降順)、
 * 状態の境界(半開区間)、flows。名前と所属は今日の値(入社前の u0015 は名前・所属とも null)。今日 = 2026-10-13(境界のテストは日を動かす)。初期データの代理は消してから始める(テストの TX の中)。
 */
final class ProxyPagesTest extends TransactionalTestCase
{
    use RegistersProxies;
    use RunsWorkflowOperations;

    private const PLAN = 'プロジェクト計画の承認';

    /** 初期データのフロー(並び = sort_key) */
    private const FLOWS = [
        ['code' => 'project_plan', 'name' => self::PLAN],
        ['code' => 'issue_resolution', 'name' => '課題の対応の承認'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo($this->operationDay()->setTime(12, 0));
        WorkflowProxy::query()->delete();
    }

    /**
     * u0003 の代理の設定: 自分が代理元の行(有効 2 つは開始日の降順、同じ開始日は id の降順 → 開始前 → 終了)と、自分が代理先の行。
     * ほかの人どうしの行は入らない。入社前の u0015 は所属が null。
     */
    public function test_the_own_tables_hold_only_the_users_rows_in_the_order_of_status_and_start(): void
    {
        $user = $this->u('u0003');
        $plan = $this->flowIdOf('project_plan');
        $since1001 = $this->addProxy($user, $this->u('u0009'), start: '2026-10-01');
        $since1005 = $this->addProxy($user, $this->u('u0008'), start: '2026-10-05');
        $since1005Later = $this->addProxy($user, $this->u('u0010'), start: '2026-10-05');
        $apply = WorkflowProxyKind::Apply;
        $upcoming = $this->addProxy($user, $this->u('u0015'), $apply, $plan, '2026-10-20', '2026-11-01');
        $ended = $this->addProxy($user, $this->u('u0002'), start: '2026-09-01', end: '2026-10-01');
        $incoming = $this->addProxy($this->u('u0016'), $user, flowId: $plan, start: '2026-10-08', end: '2026-10-19');
        $this->addProxy($this->u('u0010'), $this->u('u0002'));
        $this->actingAs($user);
        $approve = fn (WorkflowProxy $proxy, array $person, string $start, ?string $end, string $status) => $this->row(
            $proxy, $person, 'approve', null, $start, $end, $status,
        );

        $this->get('/workflow/proxies')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Workflow/Proxies')
            ->where('outgoing', [
                $approve($since1005Later, $this->person('u0010', '経理部'), '2026-10-05', null, 'active'),
                $approve($since1005, $this->person('u0008', '開発本部'), '2026-10-05', null, 'active'),
                $approve($since1001, $this->person('u0009', '総務部'), '2026-10-01', null, 'active'),
                $this->row(
                    $upcoming, $this->person('u0015', null), 'apply', self::PLAN,
                    '2026-10-20', '2026-10-31', 'upcoming',
                ),
                $approve($ended, $this->person('u0002', '東京営業課'), '2026-09-01', '2026-09-30', 'ended'),
            ])
            ->where('incoming', [
                $this->row(
                    $incoming, $this->person('u0016', '第二営業部'), 'approve', self::PLAN,
                    '2026-10-08', '2026-10-18', 'active',
                ),
            ])
            ->where('flows', self::FLOWS));
    }

    /**
     * 状態の境界(行 = 開始日 2026-10-14、最後の有効な日 2026-10-19 = 行の end_date 2026-10-20): 開始日の前日は開始前、開始日と最後の有効な日は有効、
     * その翌日は終了。終了日なしの行は開始日から有効。代理の管理の「有効な代理だけ」も同じ境界で入る・外れる。
     */
    public function test_the_status_and_the_active_filter_follow_the_half_open_period(): void
    {
        $user = $this->u('u0003');
        $bounded = $this->addProxy($user, $this->u('u0009'), start: '2026-10-14', end: '2026-10-20');
        $this->addProxy($user, $this->u('u0008'), start: '2026-10-13');
        $statuses = [];
        $activeIds = [];

        foreach (['2026-10-13', '2026-10-14', '2026-10-19', '2026-10-20'] as $day) {
            $this->travelTo(CarbonImmutable::parse("{$day} 12:00"));
            $statuses[$day] = $this->actingAs($user)->get('/workflow/proxies')->viewData('page')['props']['outgoing'];
            $this->actingAs($this->u('u0004'));
            $active = array_column($this->adminProps('?active=1')['proxies']['data'], 'id');
            $activeIds[$day] = in_array($bounded->id, $active, true);
        }

        $codeAndStatus = fn (array $rows) => array_map(
            fn (array $row) => [$row['person']['code'], $row['status']], $rows,
        );
        $this->assertSame([
            '2026-10-13' => [['u0008', 'active'], ['u0009', 'upcoming']],
            '2026-10-14' => [['u0009', 'active'], ['u0008', 'active']],
            '2026-10-19' => [['u0009', 'active'], ['u0008', 'active']],
            '2026-10-20' => [['u0008', 'active'], ['u0009', 'ended']],
        ], array_map($codeAndStatus, $statuses));
        $this->assertSame('2026-10-19', $statuses['2026-10-13'][1]['endDate']);
        $this->assertSame(
            ['2026-10-13' => false, '2026-10-14' => true, '2026-10-19' => true, '2026-10-20' => false],
            $activeIds,
        );
    }

    /**
     * 代理の管理の行の形と絞り込み(代理元・代理先・種類・有効な代理だけ)。人のクエリは id から引き、解釈できない・無い値は null(絞り込まない)。
     * 種類の知らない値は null、active は 1 だけが真。
     */
    public function test_the_admin_rows_and_the_filters(): void
    {
        [$issue, $plan] = [$this->flowIdOf('issue_resolution'), $this->flowIdOf('project_plan')];
        $approve = WorkflowProxyKind::Approve;
        $ended = $this->addProxy($this->u('u0010'), $this->u('u0002'), $approve, $issue, '2026-08-30', '2026-09-29');
        $planned = $this->addProxy($this->u('u0016'), $this->u('u0003'), $approve, $plan, '2026-10-08', '2026-10-19');
        $planned->update(['registered_by_user_id' => $this->u('u0004')->id]);
        $applying = $this->addProxy($this->u('u0010'), $this->u('u0009'), WorkflowProxyKind::Apply, null, '2026-09-29');
        $this->actingAs($this->u('u0004'));
        $ids = fn (string $query) => array_column($this->adminProps($query)['proxies']['data'], 'id');
        $u0016 = $this->u('u0016');
        $missing = (int) User::query()->max('id') + 1000;

        $this->assertSame([
            'id' => $planned->id, 'kind' => 'approve', 'flowName' => self::PLAN, 'startDate' => '2026-10-08',
            'endDate' => '2026-10-18', 'status' => 'active', 'principal' => $this->person('u0016', '第二営業部'),
            'proxy' => $this->person('u0003', '第一営業部'), 'registeredBy' => $this->plainPerson('u0004'),
        ], $this->adminProps('')['proxies']['data'][0]);
        $this->assertSame([$planned->id, $applying->id, $ended->id], $ids(''));
        $this->assertSame([$planned->id], $ids("?principal={$u0016->id}"));
        $this->assertSame([$applying->id], $ids('?proxy='.$this->u('u0009')->id));
        $this->assertSame([$applying->id], $ids('?kind=apply'));
        $this->assertSame([$planned->id, $applying->id], $ids('?active=1'));
        $principal = $this->linkedPerson('u0016', 'sales-2');
        $this->assertSame(
            ['principal' => $principal, 'proxy' => null, 'kind' => 'approve', 'active' => true],
            $this->adminProps("?principal={$u0016->id}&kind=approve&active=1")['query'],
        );
        $this->assertSame(
            ['principal' => null, 'proxy' => null, 'kind' => null, 'active' => false],
            $this->adminProps("?principal=abc&proxy={$missing}&kind=confirm&active=true")['query'],
        );
        $all = [$planned->id, $applying->id, $ended->id];
        $this->assertSame($all, $ids("?principal=abc&proxy={$missing}&kind=confirm"));
        $this->assertSame(self::FLOWS, $this->adminProps('')['flows']);
    }

    /**
     * 20 件ずつ(21 件で 2 ページ。最後より後のページは最後のページに詰める)。
     */
    public function test_the_admin_list_pages_by_twenty(): void
    {
        foreach (range(1, 21) as $ignored) {
            $this->addProxy($this->u('u0001'), $this->u('u0002'));
        }

        $this->actingAs($this->u('u0004'));
        $page = fn (string $query) => $this->adminProps($query)['proxies'];

        $this->assertSame([20, 1, 2, 21], $this->pageSummary($page('')));
        $this->assertSame([1, 2, 2, 21], $this->pageSummary($page('?page=2')));
        $this->assertSame([1, 2, 2, 21], $this->pageSummary($page('?page=99')));
    }

    /**
     * today = サーバーの今日(判定の入力の日)。2 つのページとゲストで同じ値で、日を動かすと変わる。
     */
    public function test_today_is_the_date_of_the_server(): void
    {
        $today = fn (string $path) => $this->get($path)->assertOk()->viewData('page')['props']['today'];
        $values = [];

        foreach (['2026-10-13', '2026-10-14'] as $day) {
            $this->travelTo(CarbonImmutable::parse("{$day} 12:00"));
            $guest = $today('/workflow/proxies');
            $this->actingAs($this->u('u0004'));
            $values[$day] = [$guest, $today('/workflow/proxies'), $today('/workflow/admin/proxies')];
            $this->app['auth']->forgetGuards();
        }

        $this->assertSame([
            '2026-10-13' => ['2026-10-13', '2026-10-13', '2026-10-13'],
            '2026-10-14' => ['2026-10-14', '2026-10-14', '2026-10-14'],
        ], $values);
    }

    /**
     * ゲストの代理の設定は両方の表が空(flows は同じ)。
     */
    public function test_a_guest_has_empty_tables(): void
    {
        $this->addProxy($this->u('u0003'), $this->u('u0009'));

        $this->get('/workflow/proxies')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('outgoing', [])->where('incoming', [])->where('flows', self::FLOWS));
    }

    private function u(string $code): User
    {
        return $this->seededUser($code);
    }

    /**
     * @param  array<string, mixed>  $proxies
     * @return array{int, int, int, int} [行の数, 今のページ, 最後のページ, 件数]
     */
    private function pageSummary(array $proxies): array
    {
        return [count($proxies['data']), $proxies['currentPage'], $proxies['lastPage'], $proxies['total']];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminProps(string $query): array
    {
        return $this->get('/workflow/admin/proxies'.$query)->assertOk()->viewData('page')['props'];
    }

    /**
     * @param  array<string, mixed>  $person
     * @return array<string, mixed>
     */
    private function row(
        WorkflowProxy $proxy,
        array $person,
        string $kind,
        ?string $flowName,
        string $start,
        ?string $end,
        string $status,
    ): array {
        return [
            'id' => $proxy->id, 'person' => $person, 'kind' => $kind, 'flowName' => $flowName,
            'startDate' => $start, 'endDate' => $end, 'status' => $status,
        ];
    }

    /**
     * @return array{id: int, code: string, name: string|null, department: string|null}
     */
    private function person(string $code, ?string $department): array
    {
        return [...$this->plainPerson($code), 'department' => $department];
    }

    /**
     * 名前の札に出す人(絞り込みの欄の札。主所属の組織の id = 札の「組織図で見る」の行き先)。
     *
     * @return array{id: int, code: string, name: string|null, mainDepartmentId: int}
     */
    private function linkedPerson(string $code, string $departmentCode): array
    {
        $departmentId = Department::query()->where('code', $departmentCode)->sole()->id;

        return [...$this->plainPerson($code), 'mainDepartmentId' => $departmentId];
    }

    /**
     * @return array{id: int, code: string, name: string|null}
     */
    private function plainPerson(string $code): array
    {
        $user = $this->seededUser($code);
        $names = [
            'u0002' => '中村 美咲', 'u0003' => '高橋 誠', 'u0004' => '伊藤 涼子', 'u0008' => '加藤 由美', 'u0009' => '吉田 達也',
            'u0010' => '山田 恵', 'u0016' => '清水 美穂',
            // 入社前(2026-11-01 から)の u0015 は今日の名前が無い(画面はコードを出す = 設計/ワークフロー/画面/README.md 1.2)
            'u0015' => null,
        ];

        return ['id' => $user->id, 'code' => $code, 'name' => $names[$code]];
    }
}
