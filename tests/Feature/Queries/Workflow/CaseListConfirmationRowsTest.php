<?php

namespace Tests\Feature\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use Carbon\CarbonImmutable;
use Tests\Support\ReadsCaseLists;
use Tests\Support\TransactionalTestCase;

/**
 * 案件一覧の未確認・確認済の行の項目と、項目を求めないタブの行(docs の 設計/ワークフロー/案件の運用/認可と一覧.md の 2.2 節・6 節の「行の項目」):
 * 確認するステップ・確認が開いた日時(最も古いもの)・can.confirm、確認したステップ・確認日時(最新のもの)。求めないタブの行は null・空・偽。
 * 問い合わせの回数は行の数に依らない(2 件と 20 件で同じ)。フロー = 申請(u0008)→ c1(u0005。確認する人 u0007)→ c2(u0006。確認する人 u0007)
 * → c3(u0013)。
 */
final class CaseListConfirmationRowsTest extends TransactionalTestCase
{
    use ReadsCaseLists;

    private const CONFIRMER = 'u0007';

    private const NONE = [null, [], null, null, false, false];

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
     * 確認するステップは未確認のノードだけで、日時は開いた日時の最も古いもの。確認したステップは確認したノードで、日時は確認日時の最新のもの。
     */
    public function test_the_unconfirmed_and_confirmed_steps_with_their_times(): void
    {
        $both = $this->applyAt('2026-10-13 01:00:00');
        $partly = $this->applyAt('2026-10-13 01:10:00');
        $this->travelTo(CarbonImmutable::parse('2026-10-13 02:00:00'));
        $this->approve($both, 'c1', 'u0005');
        $this->approve($partly, 'c1', 'u0005');
        $this->travelTo(CarbonImmutable::parse('2026-10-13 03:00:00'));
        $this->confirmAs($partly, self::CONFIRMER);
        $this->travelTo(CarbonImmutable::parse('2026-10-13 04:00:00'));
        $this->approve($both, 'c2', 'u0006');
        $this->approve($partly, 'c2', 'u0006');

        $this->assertSame([['c1', 'c2'], $this->iso('02:00'), true], $this->confirmationOf('unconfirmed', $both));
        $this->assertSame([['c2'], $this->iso('04:00'), true], $this->confirmationOf('unconfirmed', $partly));
        $confirmed = $this->confirmationOf('confirmed', $partly);
        $this->assertSame([['c1'], $this->iso('03:00')], [$confirmed[0], $confirmed[1]]);

        $this->travelTo(CarbonImmutable::parse('2026-10-13 05:00:00'));
        $this->confirmAs($partly, self::CONFIRMER);

        $this->assertSame([['c1', 'c2'], $this->iso('05:00'), false], $this->confirmationOf('confirmed', $partly));
        $this->assertNotContains($partly->id, $this->listedIds(self::CONFIRMER, 'unconfirmed'));
    }

    /**
     * 項目を求めないタブの行は、期限・代理元・確認・組が空の値で、can.bulkApprove・can.confirm は偽(確認できる案件でも)。
     */
    public function test_rows_of_tabs_that_do_not_ask_have_empty_values(): void
    {
        $case = $this->applyAt('2026-10-13 01:00:00');
        $this->approve($case, 'c1', 'u0005');

        $this->assertSame(self::NONE, $this->extrasOf('u0005', 'processed', $case));
        $this->assertSame(self::NONE, $this->extrasOf(self::APPLICANT, 'processed', $case));
        $this->assertTrue($this->rowOf(self::CONFIRMER, 'unconfirmed', $case)['can']['confirm']);
        [$deadline, $principals, , $approval, $canBulkApprove] = $this->extrasOf(self::CONFIRMER, 'unconfirmed', $case);
        $this->assertSame([null, [], null, false], [$deadline, $principals, $approval, $canBulkApprove]);
        $this->assertNull($this->extrasOf('u0006', 'pending', $case)[2]);
    }

    /**
     * 未処理(代理元と組を引く)・未確認・確認済のタブの問い合わせの回数が、2 行と 20 行で同じ。
     */
    public function test_the_number_of_queries_does_not_depend_on_the_number_of_rows(): void
    {
        $this->addProxy($this->seededUser('u0013'), $this->seededUser('u0005'));
        $this->createCases(2);
        $two = $this->queryCounts();

        $this->createCases(18);

        $this->assertCount(20, $this->listOf('u0005', 'pending')['cases']['data']);
        $this->assertCount(20, $this->listOf(self::CONFIRMER, 'unconfirmed')['cases']['data']);
        $this->assertCount(20, $this->listOf(self::CONFIRMER, 'confirmed')['cases']['data']);
        $this->assertSame($two, $this->queryCounts());
    }

    /**
     * c3 まで進めた案件(u0007 は c1 を確認済・c2 が未確認。u0005 は c3 の処理対象者 u0013 の代理先として未処理に出る)。
     */
    private function createCases(int $count): void
    {
        foreach (range(1, $count) as $ignored) {
            $case = $this->applyAs($this->seededUser(self::APPLICANT));
            $this->approve($case, 'c1', 'u0005');
            $this->confirmAs($case, self::CONFIRMER);
            $this->approve($case, 'c2', 'u0006');
        }
    }

    /**
     * @return list<int> [u0005 の未処理, u0007 の未確認, u0007 の確認済] の問い合わせの数
     */
    private function queryCounts(): array
    {
        $countOf = fn (array $list) => count($this->recordedSql(fn () => $this->listOf(...$list)));

        return array_map($countOf, [
            ['u0005', 'pending'],
            [self::CONFIRMER, 'unconfirmed'],
            [self::CONFIRMER, 'confirmed'],
        ]);
    }

    /**
     * 行の確認の項目 [ステップ, 日時, can.confirm](キーは型の 2 つ)。
     *
     * @return list<mixed>
     */
    private function confirmationOf(string $tab, WorkflowCase $case): array
    {
        $row = $this->rowOf(self::CONFIRMER, $tab, $case);
        $confirmation = $row['confirmation'] ?? [];
        $this->assertSame(['stepNames', 'at'], array_keys($confirmation));

        return [...array_values($confirmation), $row['can']['confirm']];
    }

    /**
     * 行の追加の項目 [deadline, principals, confirmation, bulkApproval, can.bulkApprove, can.confirm]。
     *
     * @return list<mixed>
     */
    private function extrasOf(string $userCode, string $tab, WorkflowCase $case): array
    {
        $row = $this->rowOf($userCode, $tab, $case);
        $can = $row['can'];

        return [
            $row['deadline'], $row['principals'], $row['confirmation'], $row['bulkApproval'],
            $can['bulkApprove'], $can['confirm'],
        ];
    }

    private function iso(string $time): string
    {
        return CarbonImmutable::parse("2026-10-13 {$time}:00")->toIso8601String();
    }
}
