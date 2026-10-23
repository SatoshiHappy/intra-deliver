<?php

namespace Tests\Feature\Queries\Workflow;

use App\Actions\Workflow\ApproveCase;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\ActiveApplicationsHomeSection;
use App\Queries\Workflow\PendingCasesHomeSection;
use App\Queries\Workflow\WorkflowCountersHomeSection;
use Carbon\CarbonImmutable;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * ホームの節の期限と保留中(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.4 節・6 節の「ホームの節」、画面/案件の画面.md の 6.2 節):
 * 未処理の表の期限の列(超過・当日・期限なし)と状態の保留中、件数カードの内訳の保留中と期限超過(期限超過は内訳の和に入らない)、経路の保留中、
 * 今月の処理済みは保留の行を数えない。今日 = 2026-10-13。u0005 が処理する人、u0008 が申請者。
 */
final class WorkflowHomeDeadlineTest extends TransactionalTestCase
{
    use ReadsCaseLists;

    /** @var array{WorkflowCase, WorkflowCase, WorkflowCase} [期限超過, 期限が今日, 保留中(期限なし)] */
    private array $cases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow('wf_free', '期限なし', [[$this->userCondition('u0005')]]);
        $this->addLine($this->listNode('d', ['u0005'], 2));
        $overdue = $this->applyAt('2026-10-10 03:00:00');
        $today = $this->applyAt('2026-10-11 03:00:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-12 03:00:00'));
        $applicant = $this->seededUser(self::APPLICANT);
        $held = $this->applyAs($applicant, 'wf_free');
        $this->holdOn($held, 'approval1', 'u0005');
        $this->operate(ApproveCase::class, $this->seededUser('u0005'), $this->applyAs($applicant, 'wf_free'));
        $this->cases = [$overdue, $today, $held];
    }

    /**
     * 期限のセル = 超過は「{期限日} 期限超過」の札(rejected)、期限があれば「{期限日}」、無ければ「—」。保留中の状態は「保留中」(returned)。
     */
    public function test_the_pending_table_has_the_due_column_and_the_held_state(): void
    {
        [$overdue, $today, $held] = $this->cases;
        $section = $this->section(PendingCasesHomeSection::class, 'u0005');
        $cells = array_column($section['rows'] ?? [], 'cells', 'code');

        $this->assertSame(['due', '期限', 'status'], array_values(($section['columns'] ?? [])[4] ?? []));
        // 行の code は案件の id の文字列(キーにすると整数になる)
        $this->assertSame([$held->id, $today->id, $overdue->id], array_keys($cells));
        $this->assertSame([
            [['承認待ち', 'pending'], ['2026-10-12 期限超過', 'rejected']],
            [['承認待ち', 'pending'], ['2026-10-13', null]],
            [['保留中', 'returned'], ['—', null]],
        ], array_map(fn (WorkflowCase $case) => $this->stateAndDue($cells[$case->id]), [$overdue, $today, $held]));
    }

    /**
     * 未処理の申請 = 状態の札ごとの数と、その外の期限超過。進行中の申請に保留中。今月の処理済みは承認の 1 行だけ(保留の行を数えない)。
     */
    public function test_the_counters_show_the_held_and_the_overdue(): void
    {
        $this->assertSame([3, '承認待ち 2・保留中 1・期限超過 1'], $this->counter('u0005', 'pending'));
        $this->assertSame([1, '承認 1'], $this->counter('u0005', 'processed-this-month'));
        $this->assertSame([3, '承認待ち 2・保留中 1'], $this->counter(self::APPLICANT, 'active-applications'));
    }

    /**
     * 経路の保留中のノードは現在のノードの点で語は「保留中」、note は「保留中：{ステップ名}」。
     */
    public function test_the_route_of_a_held_case(): void
    {
        $held = $this->cases[2];
        $section = $this->section(ActiveApplicationsHomeSection::class, self::APPLICANT);
        $items = array_column($section['items'] ?? [], null, 'code');
        $item = $items[$held->id] ?? [];
        $steps = array_column($item['steps'] ?? [], null, 'label');

        $this->assertSame('保留中：承認1', $item['note'] ?? null);
        $this->assertSame(['current', '保留中'], [$steps['承認1']['state'] ?? null, $steps['承認1']['stateLabel'] ?? null]);
    }

    /**
     * @param  class-string<PendingCasesHomeSection|ActiveApplicationsHomeSection>  $class
     * @return array<string, mixed>|null
     */
    private function section(string $class, string $userCode): ?array
    {
        return app($class)->section($this->contextOf($this->seededUser($userCode)));
    }

    /**
     * 件数カードの 1 枚の [件数, 内訳]。
     *
     * @return array{int|null, string|null}
     */
    private function counter(string $userCode, string $code): array
    {
        $section = app(WorkflowCountersHomeSection::class)->section($this->contextOf($this->seededUser($userCode)));
        $item = array_column($section['items'] ?? [], null, 'code')[$code] ?? [];

        return [$item['count'] ?? null, $item['note'] ?? null];
    }

    /**
     * 表の行の状態と期限のセルの [語, 札の色]。
     *
     * @param  list<array{text: string, tone: string|null, mark: string|null}>  $cells
     * @return list<array{string, string|null}>
     */
    private function stateAndDue(array $cells): array
    {
        return [[$cells[3]['text'], $cells[3]['tone']], [$cells[4]['text'], $cells[4]['tone']]];
    }
}
