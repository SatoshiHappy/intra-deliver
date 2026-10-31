<?php

namespace Tests\Feature\Queries\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Menu\Api\HomeSections;
use App\Queries\Menu\QuickActionsHomeSection;
use App\Queries\Workflow\ActiveApplicationsHomeSection;
use App\Queries\Workflow\CaseListQuery;
use App\Queries\Workflow\PendingCasesHomeSection;
use App\Queries\Workflow\ProcessedThisMonthHomeSection;
use App\Queries\Workflow\WorkflowCountersHomeSection;
use App\Queries\Workflow\WorkflowHomeSections;
use App\Queries\Workflow\WorkflowLeadHomeSection;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsWorkflowCases;
use Tests\Support\TransactionalTestCase;

/**
 * ホームのワークフローの 5 つの節(docs の 設計/ワークフロー/画面/ホームと意匠とE2E.md 7 節・9 節の「ホームの節の Feature テスト」、口 = 設計/メニュー/ホーム.md 6.2)。
 * 初期データの案件の見本(設計/ワークフロー/初期データ.md 4 節、設計/ワークフロー/案件の運用/初期データ.md 4 節)を使う。処理日時は見本の操作の時刻
 * (流す日からの相対で月をまたぐ)なので、各テストの始めに履歴の処理日時を d(2026-10-13)の中の同じ時刻に書き換える(並びは履歴の id で決まる)。
 * d に有効でない利用者がゲストになることは HomeControllerTest(HTTP の要求)が確かめる。
 */
final class WorkflowHomeSectionsTest extends TransactionalTestCase
{
    use BuildsWorkflowCases;

    private const DATE = '2026-10-13';

    /** @var list<class-string<HomeSections>> */
    private const SECTIONS = [WorkflowLeadHomeSection::class, WorkflowCountersHomeSection::class, PendingCasesHomeSection::class, ProcessedThisMonthHomeSection::class, ActiveApplicationsHomeSection::class];

    protected function setUp(): void
    {
        parent::setUp();
        WorkflowCaseHistory::query()->update(['created_at' => '2026-10-13 03:00:00+00']);
    }

    /**
     * ゲストには案内の一文(workflow-lead)だけが出て、ほかの 4 つは null。
     */
    public function test_a_guest_gets_only_the_lead_of_the_five(): void
    {
        $text = 'ゲストは申請も処理もできません。';
        $this->assertSame(
            ['code' => 'workflow-lead', 'kind' => 'lead', 'area' => 'hero', 'text' => $text, 'action' => null],
            app(WorkflowLeadHomeSection::class)->section($this->contextOf(null)),
        );

        foreach (array_slice(self::SECTIONS, 1) as $class) {
            $this->assertNull(app($class)->section($this->contextOf(null)), $class);
        }
    }

    public function test_the_lead_and_the_counters_of_the_applicant(): void
    {
        $this->assertSame('未処理の申請は1件、進行中の申請は9件です。', $this->section(WorkflowLeadHomeSection::class, 'u0001')['text'] ?? null);
        $this->assertSame([
            ['pending', '未処理の申請', 'inbox', 1, '再申請待ち 1', route('workflow.cases.index', ['tab' => 'pending'])],
            ['active-applications', '進行中の申請', 'send', 9, '承認待ち 7・再申請待ち 1・保留中 1', route('workflow.cases.index', ['tab' => 'processed'])],
            ['processed-this-month', '今月の処理済み', 'folder', 0, null, route('workflow.cases.index', ['tab' => 'processed'])],
            ['draft', '一時保存', 'file-text', 1, null, route('workflow.cases.index', ['tab' => 'draft'])],
        ], $this->counters('u0001'));
    }

    public function test_the_counters_of_the_section_chief(): void
    {
        $this->assertSame([
            ['pending', 3, '承認待ち 3'],
            ['active-applications', 0, null],
            ['processed-this-month', 13, '承認 8・承認終了 1・否認 1・差戻し 3'],
            ['draft', 0, null],
        ], array_map(fn (array $item) => [$item[0], $item[3], $item[4]], $this->counters('u0002')));
    }

    public function test_the_pending_table_of_the_section_chief(): void
    {
        $section = $this->section(PendingCasesHomeSection::class, 'u0002');

        $this->assertSame(['workflow-pending', 'table', 'main', '未処理の申請', '処理を待っている申請はありません'], [$section['code'] ?? null, $section['kind'] ?? null, $section['area'] ?? null, $section['heading'] ?? null, $section['emptyText'] ?? null]);
        $this->assertSame([['applied-on', '申請日', 'date'], ['name', '申請名', 'title'], ['applicant', '申請者', 'text'], ['state', '状態', 'status'], ['due', '期限', 'status']], array_map(array_values(...), $section['columns'] ?? []));
        $this->assertSame(['label' => '申請一覧', 'url' => route('workflow.cases.index', ['tab' => 'pending'])], $section['more'] ?? null);
        // 更新の新しい順。期限 = WF-000005 の課長は D(d の 2 日後)、課題の WF-000015・WF-000002 は期限なし(設計/ワークフロー/案件の運用/初期データ.md 3 節・4.2)
        $this->assertSame(
            [$this->pendingRow(5, '2026-10-15'), $this->pendingRow(15, '—', '山田 恵'), $this->pendingRow(2, '—')],
            $section['rows'] ?? null,
        );
    }

    /**
     * 未処理の表の行(案件・申請日・申請名・申請者・状態)は、案件一覧の未処理のタブの行と同じ読み取りから作る(一覧の行 = CaseRows)。
     * 申請日は申請日時の Asia/Tokyo の暦の日付、状態は現在のノードの状態の語(承認待ち・再申請待ち・保留中)。
     */
    public function test_the_pending_table_shows_the_rows_of_the_pending_tab_of_the_case_list(): void
    {
        $states = ['waiting' => '承認待ち', 'reapply' => '再申請待ち', 'hold' => '保留中'];

        foreach (['u0001', 'u0002', 'u0003'] as $code) {
            $context = $this->contextOf(User::query()->where('code', $code)->sole());
            $listed = array_slice(app(CaseListQuery::class)->list($context, 'pending', null, null)['cases']['data'], 0, PendingCasesHomeSection::LIMIT);

            $this->assertSame(
                array_map(fn (array $row) => [
                    (string) $row['id'],
                    CarbonImmutable::parse((string) $row['appliedAt'])->setTimezone('Asia/Tokyo')->toDateString(),
                    $row['name'],
                    $row['applicant']['name'] ?? $row['applicant']['code'],
                    $states[$row['currentSummary']['state'] ?? ''] ?? null,
                ], $listed),
                array_map(fn (array $row) => [$row['code'], $row['cells'][0]['text'], $row['cells'][1]['text'], $row['cells'][2]['text'], $row['cells'][3]['text']], $this->section(PendingCasesHomeSection::class, $code)['rows'] ?? []),
                $code,
            );
        }
    }

    public function test_the_processed_this_month_table_of_the_section_chief_has_the_latest_five_histories(): void
    {
        $section = $this->section(ProcessedThisMonthHomeSection::class, 'u0002');

        $this->assertSame(['workflow-processed-this-month', '今月の処理済み', '今月に処理した申請はありません', ['label' => '申請一覧', 'url' => route('workflow.cases.index', ['tab' => 'processed'])]], [$section['code'] ?? null, $section['heading'] ?? null, $section['emptyText'] ?? null, $section['more'] ?? null]);
        // 担当変更(WF-000016)は数えない。処理日時はそろえたので、履歴の id の新しい順
        $this->assertSame([
            [14, '佐々木 陽太', ['承認', 'settled', '✓']],
            [13, '佐々木 陽太', ['承認', 'settled', '✓']],
            [12, '佐々木 陽太', ['承認', 'settled', '✓']],
            [11, '佐々木 陽太', ['承認', 'settled', '✓']],
            [10, '佐々木 陽太', ['承認', 'settled', '✓']],
        ], array_map(fn (array $row) => [
            $this->caseOfRow($row)->case_number,
            $row['cells'][2]['text'],
            array_values($row['cells'][3]),
        ], $section['rows'] ?? []));
        $first = $section['rows'][0] ?? null;
        $history = WorkflowCaseHistory::query()->where('workflow_case_id', $this->caseNumbered(14)->id)->where('user_id', $this->userId('u0002'))->sole();
        $this->assertSame([(string) $history->id, '2026-10-13T03:00:00+00:00', $this->caseNumbered(14)->name], [$first['code'] ?? null, $first['cells'][0]['text'] ?? null, $first['cells'][1]['text'] ?? null]);
    }

    /**
     * 今月の境目: d の月の 1 日 0 時(Asia/Tokyo)の行は入り、前の月の末日 23:59:59 の行は入らない。申請・再申請・取止め・引戻しの行は数えない。
     */
    public function test_this_month_is_the_month_of_the_date_in_tokyo_and_only_the_approval_actions_count(): void
    {
        $historyIds = WorkflowCaseHistory::query()->where('user_id', $this->userId('u0002'))->orderBy('id')->pluck('id')->all();
        WorkflowCaseHistory::query()->whereKey($historyIds[0])->update(['created_at' => '2026-09-30 15:00:00+00']);
        WorkflowCaseHistory::query()->whereKey($historyIds[1])->update(['created_at' => '2026-09-30 14:59:59+00']);
        $wf3 = $this->caseNumbered(3);
        WorkflowCaseHistory::factory()->create(['workflow_case_id' => $wf3->id, 'workflow_case_node_id' => $this->nodesOf($wf3)[1]->id, 'user_id' => $this->userId('u0002'), 'action' => WorkflowAction::PullBack]);

        $this->assertSame([12, '承認 7・承認終了 1・否認 1・差戻し 3'], [$this->counters('u0002')[2][3], $this->counters('u0002')[2][4]]);
    }

    public function test_the_active_applications_of_the_applicant_are_the_latest_three_with_their_steps(): void
    {
        $section = $this->section(ActiveApplicationsHomeSection::class, 'u0001');
        $reapply = ['label' => '再申請', 'url' => route('workflow.reapplications.create', $this->caseNumbered(4)->id), 'method' => 'get', 'data' => []];

        $this->assertSame(['workflow-active-applications', 'routes', 'side', '進行中の申請', '進行中の申請はありません'], [$section['code'] ?? null, $section['kind'] ?? null, $section['area'] ?? null, $section['heading'] ?? null, $section['emptyText'] ?? null]);
        $done = fn (string $label) => [$label, 'done', '処理済み'];
        $council = ['関係部署の合議', 'current', '承認待ち'];
        $owner = ['責任者', 'upcoming', '到達していない'];
        $upcoming = fn (string $label) => [$label, 'upcoming', '到達していない'];
        // 更新の新しい順(設計/ワークフロー/案件の運用/初期データ.md 4.2・4.3): WF-000013 = D − 1 の保留、WF-000014 = D − 2 の承認、
        // WF-000010 と WF-000005 は D − 3 10:00 で同じ時刻なので id の新しい WF-000010
        $this->assertSame([
            [13, '保留中：部長', [$done('申請'), $done('課長'), $done('予算'), ['部長', 'current', '保留中'], $upcoming('分岐終了'), $upcoming('関係部署の合議'), $owner], null],
            [14, '承認待ち：部長', [$done('申請'), $done('課長'), $done('予算'), ['部長', 'current', '承認待ち'], $upcoming('分岐終了'), $upcoming('関係部署の合議'), $owner], null],
            [10, '承認待ち：関係部署の合議(承認 1、承認 2)', [$done('申請'), $done('課長'), $done('予算'), ['部長', 'skipped', '省略'], $done('分岐終了'), $council, $owner], null],
        ], $this->itemsOf($section));
        $this->assertSame([$this->caseNumbered(13)->name, route('workflow.cases.show', $this->caseNumbered(13)->id)], [$section['items'][0]['title'] ?? null, $section['items'][0]['url'] ?? null]);

        // 再申請待ちの WF-000004 を更新の新しい順の先頭にすると、経路と「再申請」が出る(版 3 の見本の追加で 4 件目以降になったため、更新日時を動かして確かめる)
        $this->caseNumbered(4)->forceFill(['updated_at' => now()->addMinute()])->save();
        $this->assertSame(
            [4, '再申請待ち：申請', [['申請', 'returned', '再申請待ち'], ['課長の承認', 'upcoming', '到達していない'], ['部長の承認', 'upcoming', '到達していない']], $reapply],
            $this->itemsOf($this->section(ActiveApplicationsHomeSection::class, 'u0001'))[0],
        );
    }

    public function test_the_pending_table_has_at_most_five_rows(): void
    {
        [$applicant, $chief] = $this->seededUsers('u0001', 'u0002');

        foreach (range(1, 4) as $ignored) {
            $case = WorkflowCase::factory()->active()->create(['user_id' => $applicant->id]);
            [$apply, $approve] = $this->caseNodes($case, 2);
            $this->process($apply, $applicant, WorkflowAction::Apply);
            $this->reach($approve, $chief);
        }

        $this->assertSame(7, $this->counters('u0002')[0][3]);
        $this->assertCount(5, $this->section(PendingCasesHomeSection::class, 'u0002')['rows'] ?? []);
    }

    /**
     * 案件の無い利用者(u0004)にも 5 つとも出す(件数 0・内訳 null・表と経路は 0 件の文)。
     */
    public function test_a_user_without_cases_gets_the_five_with_zero(): void
    {
        $this->assertSame('未処理の申請は0件、進行中の申請は0件です。', $this->section(WorkflowLeadHomeSection::class, 'u0004')['text'] ?? null);
        $this->assertSame([[0, null], [0, null], [0, null], [0, null]], array_map(fn (array $item) => [$item[3], $item[4]], $this->counters('u0004')));
        $this->assertSame([], $this->section(PendingCasesHomeSection::class, 'u0004')['rows'] ?? null);
        $this->assertSame([], $this->section(ProcessedThisMonthHomeSection::class, 'u0004')['rows'] ?? null);
        $this->assertSame([], $this->section(ActiveApplicationsHomeSection::class, 'u0004')['items'] ?? null);
    }

    /**
     * 定義も案件も無い状態(TX の中で消す)でも例外を投げず、件数 0 の節を返す(投げるとホーム全体が 500 になる)。
     */
    public function test_without_definitions_it_returns_zero_counts_without_throwing(): void
    {
        DB::statement('TRUNCATE workflow_case_draft_attachments, workflow_case_confirmations, workflow_case_confirm_targets, workflow_case_history_users, workflow_proxies, workflow_case_assignments, workflow_case_send_backs, workflow_case_edges, workflow_case_node_targets, workflow_case_histories, workflow_case_nodes, workflow_cases, workflow_route_send_backs, workflow_route_edges, workflow_route_nodes, workflow_flow_versions, workflow_flows, workflow_contents');

        $this->assertSame([0, 0, 0, 0], array_column($this->counters('u0001'), 3));
    }

    /**
     * 起動がワークフローのホームのタグに登録する順(デモはオフ。ホームと意匠とE2E.md 7 節): 一文 → 件数 → 未処理 → 今月の処理済み → 主な操作 →
     * 進行中の申請(右列 = 主な操作 → 進行中の申請)。全体のホームのタグには何も登録しない(デモの節だけ = 設計/メニュー/ホーム.md 6.2)。
     */
    public function test_the_sections_are_registered_to_the_workflow_home_in_this_order(): void
    {
        $tagged = [...app()->tagged(WorkflowHomeSections::TAG)];
        $registered = array_map(fn (HomeSections $section) => $section::class, $tagged);

        $expected = [...array_slice(self::SECTIONS, 0, 4), QuickActionsHomeSection::class, self::SECTIONS[4]];
        $this->assertSame($expected, $registered);
        $this->assertSame([], [...app()->tagged(HomeSections::TAG)]);
    }

    /**
     * @param  class-string<HomeSections>  $class
     * @return array<string, mixed>|null
     */
    private function section(string $class, string $userCode): ?array
    {
        return app($class)->section($this->contextOf(User::query()->where('code', $userCode)->sole()));
    }

    /**
     * 件数カードの項目(code・label・icon・count・note・url の並び)。
     *
     * @return list<list<mixed>>
     */
    private function counters(string $userCode): array
    {
        $section = app(WorkflowCountersHomeSection::class)->section($this->contextOf(User::query()->where('code', $userCode)->sole()));

        return array_map(array_values(...), $section['items'] ?? []);
    }

    /**
     * 未処理の表の行(状態は承認待ち)。
     *
     * @param  string  $due  期限の列の語(期限超過でない期限日か「—」)
     * @return array<string, mixed>
     */
    private function pendingRow(int $number, string $due, string $applicant = '佐々木 陽太'): array
    {
        $case = $this->caseNumbered($number);

        return [
            'code' => (string) $case->id,
            'url' => route('workflow.cases.show', $case->id),
            'cells' => [
                ['text' => (string) $case->applied_at?->setTimezone(AccessContext::TIMEZONE)->toDateString(), 'tone' => null, 'mark' => null],
                ['text' => (string) $case->name, 'tone' => null, 'mark' => null],
                ['text' => $applicant, 'tone' => null, 'mark' => null],
                ['text' => '承認待ち', 'tone' => 'pending', 'mark' => null],
                // 期限超過の形は WorkflowHomeDeadlineTest
                ['text' => $due, 'tone' => null, 'mark' => null],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function caseOfRow(array $row): WorkflowCase
    {
        return WorkflowCase::query()->whereKey(WorkflowCaseHistory::query()->whereKey((int) $row['code'])->sole()->workflow_case_id)->sole();
    }

    private function caseNumbered(int $number): WorkflowCase
    {
        return WorkflowCase::query()->where('case_number', $number)->sole();
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }

    private function contextOf(?User $user): AccessContext
    {
        return new AccessContext($user?->id, null, null, CarbonImmutable::parse(self::DATE, AccessContext::TIMEZONE), AccessContext::LOCALE_CODE);
    }

    /**
     * 進行中の申請の経路の項目の [案件番号, note, [ステップの label, state, stateLabel], action]。
     *
     * @param  array<string, mixed>|null  $section
     * @return list<array{int|null, mixed, array<array{mixed, mixed, mixed}>, mixed}>
     */
    private function itemsOf(?array $section): array
    {
        $items = is_array($section['items'] ?? null) ? $section['items'] : [];

        return array_values(array_map(fn (array $item) => [
            WorkflowCase::query()->whereKey((int) $item['code'])->sole()->case_number,
            $item['note'],
            array_map(fn (array $step) => [$step['label'], $step['state'], $step['stateLabel']], $item['steps']),
            $item['action'],
        ], $items));
    }
}
