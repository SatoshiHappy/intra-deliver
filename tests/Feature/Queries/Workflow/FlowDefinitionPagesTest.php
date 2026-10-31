<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Queries\Workflow\FlowDefinitionDetail;
use App\Queries\Workflow\FlowDefinitionList;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Closure;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * フロー定義の一覧と詳細の props(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 2 節・3 節、クエリの既定 = 画面/README.md 1.1 節、
 * 今日の版 = 定義の管理.md 2 節)。初期データのプロジェクト計画の承認(版 1 = ユーザー無効、版 2、版 3 = 分岐 B)と課題の対応の承認(版 1)。
 * 期待値は 設計/ワークフロー/ルートの深さ/初期データ.md の 2 節・3 節の値と画面の文から書いた(日付は流す日からの相対なので版の行から読む)。
 */
final class FlowDefinitionPagesTest extends TransactionalTestCase
{
    use SendsFlowDefinitionRequests;

    public function test_the_list_shows_every_flow_with_its_current_version_and_referrers(): void
    {
        $flows = app(FlowDefinitionList::class)->flows($this->today(), 'ja');

        $this->assertSame(['project_plan', 'issue_resolution'], array_column($flows, 'code'));
        $this->assertSame(['プロジェクト計画の承認', '課題の対応の承認'], array_column($flows, 'name'));
        $this->assertSame([3, 1], array_column($flows, 'versionCount'));
        $this->assertSame(['経理部', '部長'], array_column($flows, 'referrerSummary'));
        $this->assertSame(
            ['number' => 3, 'startDate' => $this->startOf(3), 'endDate' => null],
            $flows[0]['currentVersion'],
        );
        $this->assertSame(1, $flows[1]['currentVersion']['number'] ?? null);
        $this->assertNotSame('', $flows[0]['moduleName']);
    }

    /**
     * 版の並び = 番号の降順、表示の終了日 = 次の版の開始日の前日(最後は null)、今日の版だけが isCurrent。参照者の行は対象者の名前。
     */
    public function test_the_detail_lists_the_versions_and_the_referrers(): void
    {
        $detail = $this->detail([]);
        $versions = $detail['versions'];

        $this->assertSame([3, 2, 1], array_column($versions, 'number'));
        $this->assertSame([null, $this->startOf(3), $this->startOf(2)], array_column($versions, 'endDate'));
        $dayBefore = fn (int $number) => CarbonImmutable::parse($this->startOf($number))->subDay()->toDateString();
        $this->assertSame([null, $dayBefore(3), $dayBefore(2)], array_column($versions, 'displayEndDate'));
        $this->assertSame([false, false, true], array_column($versions, 'isDisabled'));
        $this->assertSame([true, false, false], array_column($versions, 'isCurrent'));
        $accounting = Department::query()->where('code', 'accounting')->sole()->id;
        $rows = [['type' => 'department', 'id' => $accounting, 'name' => '経理部']];
        $this->assertSame(['rows' => $rows], $detail['referrers']);
        $this->assertSame(['code' => self::PLAN, 'name' => 'プロジェクト計画の承認'], array_slice($detail['flow'], 0, 2));
        // 版を追加の開始日の範囲に使う、サーバーの今日(Y-m-d)
        $this->assertSame($this->today()->toDateString(), $detail['today']);
    }

    /**
     * クエリの読み方: version = 無い・数でない・そのフローに無い番号は今日の版、有る番号はその版。tab = 3 つ、ほかは diagram。
     * node = 選んだ版に有るコード、無い・別の版にだけ有るコードは申請のノード(版を替えても同じコードがあれば選んだまま)。
     */
    public function test_the_query_is_read_into_the_selected_version_tab_and_node(): void
    {
        $selected = fn (array $query) => [
            ($detail = $this->detail($query))['selectedVersion'], $detail['tab'], $detail['selectedNode']['code'],
        ];

        $this->assertSame([3, 'diagram', 'apply'], $selected([]));
        $this->assertSame([3, 'diagram', 'apply'], $selected(['version' => 'abc', 'tab' => 'other']));
        $this->assertSame([3, 'versions', 'apply'], $selected(['version' => '9', 'tab' => 'versions']));
        $this->assertSame([2, 'referrers', 'apply'], $selected(['version' => '2', 'tab' => 'referrers']));
        $this->assertSame([3, 'diagram', 'manager'], $selected(['node' => 'manager']));
        $this->assertSame([2, 'diagram', 'manager'], $selected(['version' => '2', 'node' => 'manager']));
        $this->assertSame([2, 'diagram', 'apply'], $selected(['version' => '2', 'node' => 'budget']));
        $this->assertSame([3, 'diagram', 'apply'], $selected(['node' => 'nothing']));
    }

    /**
     * 今日がユーザー無効の版の期間なら今日の版は無い: 一覧の現在の版は null、詳細の既定は最後の版。
     */
    public function test_without_a_current_version_the_default_is_the_last_version(): void
    {
        $this->travelTo(CarbonImmutable::parse($this->startOf(1), AccessContext::TIMEZONE)->setTime(12, 0));

        $flows = app(FlowDefinitionList::class)->flows($this->today(), 'ja');
        $detail = $this->detail([]);

        $this->assertNull($flows[0]['currentVersion']);
        $this->assertSame(3, $detail['selectedVersion']);
        $this->assertSame([false, false, false], array_column($detail['versions'], 'isCurrent'));
    }

    /**
     * 図で選んだステップの設定(定義の管理の画面.md 3 節の StepSettings。持たない設定は null)と、選択の欄の候補(役職 = 今日に有効な役職、
     * 指定するステップ = 定義の検査と同じ式)。
     */
    public function test_the_selected_step_and_the_options(): void
    {
        $chief = $this->detail(['node' => 'section_chief'])['selectedNode'];
        $council = $this->detail(['node' => 'council']);
        $apply = $this->detail([]);
        $budget = $this->detail(['node' => 'budget'])['selectedNode'];
        $post = CompanyPost::query()->where('code', 'section-chief')->sole()->id;
        $role = Role::query()->where('code', 'general_user')->sole()->id;
        $choice = fn (string $code, string $name) => ['code' => $code, 'name' => $name];

        $this->assertSame([
            'code' => 'section_chief', 'name' => '課長', 'kind' => 'approve', 'summary' => '申請者の所属組織の課長が承認します。',
            'target' => ['kind' => 'relative', 'base' => 'applicant', 'scope' => 'own', 'companyPostId' => $post],
            'preventsApplicantApproval' => true,
            'sendBack' => ['candidates' => [$choice('apply', '申請')], 'selected' => ['apply']],
            'decision' => null,
        ], $chief);
        $this->assertSame([
            'code' => 'council', 'name' => '関係部署の合議', 'kind' => 'parallel',
            'summary' => '課長が選択した 2 人から 5 人のうち、2 人が承認すると次へ進みます。',
            'target' => ['kind' => 'designated', 'designatorCode' => 'section_chief', 'min' => 2, 'max' => 5],
            'preventsApplicantApproval' => true,
            'sendBack' => [
                'candidates' => [$choice('apply', '申請'), $choice('section_chief', '課長'), $choice('manager', '部長')],
                'selected' => ['apply', 'section_chief'],
            ],
            'decision' => ['rule' => 'count', 'value' => 2],
        ], $council['selectedNode']);
        $this->assertSame([$choice('section_chief', '課長')], $council['options']['designators']);
        $this->assertSame(
            ['kind' => 'fixed', 'rows' => [['type' => 'role', 'id' => $role, 'name' => '一般利用者']]],
            $apply['selectedNode']['target'],
        );
        $applyNode = $apply['selectedNode'];
        $this->assertSame(['一般利用者が申請できます。', null, null], [
            $applyNode['summary'], $applyNode['preventsApplicantApproval'], $applyNode['sendBack'],
        ]);
        $this->assertSame([], $apply['options']['designators']);
        $this->assertSame(['分岐「予算」の条件で進む先が決まります。', null, null, null, null], [
            $budget['summary'], $budget['target'], $budget['preventsApplicantApproval'], $budget['sendBack'],
            $budget['decision'],
        ]);
        $president = CompanyPost::query()->where('code', 'president')->sole()->id;
        $this->assertSame(['id' => $president, 'name' => '社長'], $apply['options']['companyPosts'][0]);
        $this->assertCount(7, $apply['options']['companyPosts']);
    }

    /**
     * 詳細の props(遅延の props は評価した値)。
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    private function detail(array $query): array
    {
        $props = app(FlowDefinitionDetail::class)->show(
            self::PLAN, $query['version'] ?? null, $query['tab'] ?? null, $query['node'] ?? null, $this->today(), 'ja',
        );

        return array_map(fn (mixed $value) => $value instanceof Closure ? $value() : $value, $props);
    }

    private function startOf(int $number): string
    {
        return $this->planVersion($number)->start_date->toDateString();
    }

    private function today(): CarbonImmutable
    {
        return CarbonImmutable::today(AccessContext::TIMEZONE);
    }
}
