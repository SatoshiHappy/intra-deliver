<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseNumbers;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 案件一覧の検索の語 q(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.1 節): 申請番号(表示の形)と申請名の部分一致、大文字小文字を
 * 区別しない、%・_・\ は文字そのもの、一時保存は案件名だけに掛かりフロー名には掛からない、100 文字を超える・文字列でない・UTF-8 として
 * 読めない値は空と同じ、どのタブでも効き件数は変わらない。フロー = 申請(u0008)→ s1(u0005)。
 */
final class CaseListSearchTest extends TransactionalTestCase
{
    use ReadsCaseLists;

    private const SPECIAL = '100%達成_計画\\見直し';

    protected function setUp(): void
    {
        parent::setUp();
        $this->addLine($this->listNode('s1', ['u0005']));
    }

    public function test_matches_the_number_and_the_name_ignoring_case_and_wildcards(): void
    {
        [$special, $alpha, $other] = $this->cases();
        $number = (string) CaseNumbers::format((int) $alpha->case_number);

        $this->assertSame([$alpha->id], $this->listedIds('u0005', 'pending', q: 'ALPHA'));
        $this->assertSame([$alpha->id], $this->listedIds('u0005', 'pending', q: strtolower($number)));
        $this->assertSame([$alpha->id], $this->listedIds('u0005', 'pending', q: substr($number, 2)));
        $this->assertSame([$other->id], $this->listedIds('u0005', 'pending', q: '別の'));

        foreach (['%', '_', '\\', '%達成_'] as $wildcard) {
            $this->assertSame([$special->id], $this->listedIds('u0005', 'pending', q: $wildcard), $wildcard);
        }

        $this->assertSame([], $this->listedIds('u0005', 'pending', q: '100_達成'));
        $this->assertSame([$alpha->id], $this->listedIds(self::APPLICANT, 'processed', q: 'alpha'));
    }

    /**
     * 前後の空白は除く。100 文字までは語として読み、超える値・文字列でない値は空(絞らない)。件数は語に依らない。
     */
    public function test_reads_the_keyword_and_keeps_the_counts(): void
    {
        $all = array_map(fn (WorkflowCase $case) => $case->id, $this->cases());
        $counts = $this->listOf('u0005', 'pending')['counts'];
        $long = str_repeat('あ', 101);

        $this->assertSame('Alpha', $this->listOf('u0005', 'pending', q: "  Alpha \t")['query']['q']);
        $this->assertSame([[], str_repeat('あ', 100)], $this->searched(str_repeat('あ', 100)));
        $this->assertEqualsCanonicalizing($all, $this->searched($long)[0]);
        $this->assertSame('', $this->listOf('u0005', 'pending', q: $long)['query']['q']);
        $this->assertEqualsCanonicalizing($all, $this->searched(['Alpha'])[0]);

        foreach (['ALPHA', '%', 'nothing'] as $q) {
            $this->assertSame($counts, $this->listOf('u0005', 'pending', q: $q)['counts'], $q);
        }
    }

    /**
     * UTF-8 として読めない語は空(語の無いときと同じ行。DB に渡して誤りにしない)。
     */
    public function test_a_keyword_unreadable_as_utf8_is_empty(): void
    {
        $this->cases();

        $this->assertSame($this->searched(null), $this->searched("\xFF"));
        $this->assertSame('', $this->searched("Alpha\xFF")[1]);
    }

    /**
     * 一時保存のタブは案件名だけに掛かる(案件名の無い行に出すフロー名には掛からない)。
     */
    public function test_the_draft_tab_matches_only_the_draft_name(): void
    {
        $named = $this->draft('Alpha の下書き');
        $unnamed = $this->draft(null);
        $flowName = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->name;
        $drafts = $this->listOf(self::APPLICANT, 'draft')['cases']['data'];

        $this->assertSame($flowName, array_column($drafts, 'name', 'id')[$unnamed->id]);
        $this->assertSame([$named->id], $this->listedIds(self::APPLICANT, 'draft', q: 'alpha'));
        $this->assertSame([], $this->listedIds(self::APPLICANT, 'draft', q: mb_substr($flowName, 0, 4)));
        $counts = $this->listOf(self::APPLICANT, 'draft', q: 'alpha')['counts'];
        $this->assertSame(['draft' => 2, 'pending' => 0, 'unconfirmed' => 0], $counts);
    }

    /**
     * [記号を含む名前、Alpha、別の名前] の 3 件(u0005 の未処理)。
     *
     * @return array{WorkflowCase, WorkflowCase, WorkflowCase}
     */
    private function cases(): array
    {
        return [
            $this->applyAt('2026-10-13 01:00:00', self::SPECIAL),
            $this->applyAt('2026-10-13 02:00:00', 'Alpha 計画'),
            $this->applyAt('2026-10-13 03:00:00', '別の申請'),
        ];
    }

    /**
     * 未処理のタブを語で引いた [案件の id, 返した語]。
     *
     * @return array{list<int>, string}
     */
    private function searched(mixed $q): array
    {
        $list = $this->listOf('u0005', 'pending', q: $q);

        return [array_column($list['cases']['data'], 'id'), $list['query']['q']];
    }

    private function draft(?string $name): WorkflowCase
    {
        return WorkflowCase::factory()->draft()->createOne([
            'workflow_flow_id' => WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id,
            'user_id' => $this->seededUser(self::APPLICANT)->id,
            'draft_input' => ['name' => $name, 'comment' => null, 'values' => [], 'details' => []],
        ]);
    }
}
