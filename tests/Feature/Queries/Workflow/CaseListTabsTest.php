<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Workflow\CaseVisibility;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 案件一覧の 6 タブの件数と行(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.1 節・6 節の「6 タブの件数と行」)。
 * フロー = 申請(u0008)→ c1(u0005。確認する人 u0007)→ c2(u0006。確認する人 u0007)→ c3(u0013)。u0007 は確認だけの関係の人。
 */
final class CaseListTabsTest extends TransactionalTestCase
{
    use ReadsCaseLists;

    private const CONFIRMER = 'u0007';

    protected function setUp(): void
    {
        parent::setUp();
        $group = $this->confirmGroup(self::CONFIRMER);
        $this->addLine(
            $this->listNode('c1', ['u0005'], confirmSubjectGroupId: $group),
            $this->listNode('c2', ['u0006'], confirmSubjectGroupId: $group),
            $this->listNode('c3', ['u0013']),
        );
    }

    /**
     * 未確認 = 自分の未確認がある未完了の案件、確認済 = 確認の行がある案件(未完了・完了の切替)。確認だけの関係で確認しないまま完了した案件は
     * どちらにも出ないが閲覧はできる。ほかのタブは述語のまま。
     */
    public function test_each_tab_lists_the_cases_of_its_predicate(): void
    {
        [$one, $two, $confirmed, $completed, $confirmedCompleted] = $this->cases();
        $draft = $this->draftOfApplicant();
        $confirmer = self::CONFIRMER;

        $this->assertEqualsCanonicalizing([$one->id, $two->id], $this->listedIds($confirmer, 'unconfirmed'));
        $this->assertSame([$confirmed->id], $this->listedIds($confirmer, 'confirmed'));
        $this->assertSame([$confirmedCompleted->id], $this->listedIds($confirmer, 'confirmed', 'completed'));
        $this->assertSame([], $this->listedIds($confirmer, 'pending'));
        $this->assertSame([], $this->listedIds($confirmer, 'processed'));
        $this->assertSame([], $this->listedIds($confirmer, 'processed', 'completed'));
        $this->assertTrue($this->isVisible($confirmer, $completed));
        $this->assertEqualsCanonicalizing([$one->id, $confirmed->id], $this->listedIds('u0006', 'pending'));
        $this->assertSame([$two->id], $this->listedIds('u0013', 'pending'));
        $this->assertSame([$draft->id], $this->listedIds(self::APPLICANT, 'draft'));
        $this->assertEqualsCanonicalizing([$one->id, $two->id, $confirmed->id], $this->listedIds('u0005', 'processed'));
        $processedCompleted = $this->listedIds('u0005', 'processed', 'completed');
        $this->assertEqualsCanonicalizing([$completed->id, $confirmedCompleted->id], $processedCompleted);
    }

    /**
     * 件数は一時保存・未処理・未確認の 3 つで、案件の数(未確認のノードが 2 つの案件も 1 件)。どのタブでも同じ。
     */
    public function test_the_counts_are_the_numbers_of_cases_on_every_tab(): void
    {
        $this->cases();
        $this->draftOfApplicant();

        foreach (['draft', 'pending', 'unconfirmed', 'processed', 'confirmed', 'referenced'] as $tab) {
            $this->assertSame([0, 0, 2], $this->countsOf(self::CONFIRMER, $tab), $tab);
            $this->assertSame([1, 0, 0], $this->countsOf(self::APPLICANT, $tab), $tab);
            $this->assertSame([0, 2, 0], $this->countsOf('u0006', $tab), $tab);
        }
    }

    /**
     * 切替は処理済・確認済・参照だけが読み、一時保存・未処理・未確認では読まない(返す scope は既定)。解釈できない tab・scope は既定。
     */
    public function test_only_the_scoped_tabs_read_the_scope(): void
    {
        [$one, $two] = $this->cases();

        foreach (['draft', 'pending', 'unconfirmed'] as $tab) {
            $this->assertSame([$tab, 'active'], $this->tabAndScope(self::CONFIRMER, $tab, 'completed'));
        }

        $unconfirmed = $this->listedIds(self::CONFIRMER, 'unconfirmed', 'completed');
        $this->assertEqualsCanonicalizing([$one->id, $two->id], $unconfirmed);

        foreach (['processed', 'confirmed', 'referenced'] as $tab) {
            $this->assertSame([$tab, 'completed'], $this->tabAndScope(self::CONFIRMER, $tab, 'completed'));
            $this->assertSame([$tab, 'active'], $this->tabAndScope(self::CONFIRMER, $tab, 'COMPLETED'));
        }

        foreach (['UNCONFIRMED', 'confirm', ['confirmed'], null] as $tab) {
            $this->assertSame(['pending', 'active'], $this->tabAndScope(self::CONFIRMER, $tab, 'completed'));
        }
    }

    /**
     * 最後のページより大きいページは最後のページを返し、件数 0 なら 1 ページ目。読めないページは 1。
     */
    public function test_a_page_past_the_last_page_returns_the_last_page(): void
    {
        $drafts = array_map(fn () => $this->draftOfApplicant(), range(1, 21));
        $last = $this->listOf(self::APPLICANT, 'draft', page: '3')['cases'];
        $second = $this->listOf(self::APPLICANT, 'draft', page: '2')['cases'];

        $this->assertSame([2, 2, 21], [$last['currentPage'], $last['lastPage'], $last['total']]);
        $this->assertSame(array_column($second['data'], 'id'), array_column($last['data'], 'id'));
        $this->assertSame([$drafts[0]->id], array_column($last['data'], 'id'));

        $empty = $this->listOf(self::CONFIRMER, 'draft', page: '3')['cases'];
        $this->assertSame([1, 1, 0, []], [$empty['currentPage'], $empty['lastPage'], $empty['total'], $empty['data']]);

        foreach (['0', '-1', '1.5', 'abc', '1000000000'] as $page) {
            $this->assertSame(1, $this->listOf(self::APPLICANT, 'draft', page: $page)['cases']['currentPage'], $page);
        }
    }

    /**
     * [c1 まで、c2 まで(未確認 2 つ)、確認した、確認しないまま完了、確認してから完了]。
     *
     * @return array{WorkflowCase, WorkflowCase, WorkflowCase, WorkflowCase, WorkflowCase}
     */
    private function cases(): array
    {
        $cases = array_map(fn (int $hour) => $this->applyAt("2026-10-13 0{$hour}:00:00"), range(1, 5));
        [$one, $two, $confirmed, $completed, $confirmedCompleted] = $cases;

        foreach ($cases as $case) {
            $this->approve($case, 'c1', 'u0005');
        }

        $this->approve($two, 'c2', 'u0006');
        $this->confirmAs($confirmed, self::CONFIRMER);
        $this->confirmAs($confirmedCompleted, self::CONFIRMER);

        foreach ([$completed, $confirmedCompleted] as $case) {
            $this->approve($case, 'c2', 'u0006');
            $this->approve($case, 'c3', 'u0013');
        }

        return [$one, $two, $confirmed, $completed, $confirmedCompleted];
    }

    private function draftOfApplicant(): WorkflowCase
    {
        return WorkflowCase::factory()->draft()->createOne([
            'workflow_flow_id' => WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id,
            'user_id' => $this->seededUser(self::APPLICANT)->id,
        ]);
    }

    /**
     * 件数 [一時保存, 未処理, 未確認](件数のキーはこの 3 つだけ)。
     *
     * @return list<int>
     */
    private function countsOf(string $userCode, string $tab): array
    {
        $counts = $this->listOf($userCode, $tab)['counts'];
        $this->assertSame(['draft', 'pending', 'unconfirmed'], array_keys($counts));

        return array_values($counts);
    }

    /**
     * 返したクエリの tab と scope(q は空のまま)。
     *
     * @return array{string, string}
     */
    private function tabAndScope(string $userCode, mixed $tab, string $scope): array
    {
        $query = $this->listOf($userCode, $tab, $scope)['query'];
        $this->assertSame('', $query['q']);

        return [$query['tab'], $query['scope']];
    }

    private function isVisible(string $userCode, WorkflowCase $case): bool
    {
        $requester = new Requester($this->seededUser($userCode)->id, null);

        return app(CaseVisibility::class)->isVisible($requester, $this->operationDay(), $case->id);
    }
}
