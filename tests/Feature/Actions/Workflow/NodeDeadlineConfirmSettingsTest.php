<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Workflow\ApplyCase;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Master\Locale;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlowVersion;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\SavesNodeSettings;
use Tests\Support\TransactionalTestCase;

/**
 * ステップの設定の処理期限と確認する人の保存(docs の 設計/ワークフロー/案件の運用/定義の管理.md の 2 節・5 節の「ステップの設定のテスト」)。
 * 期待の文言は 5 節の失敗の形の表から書いた。テストのフローの版 1 = 一本道(申請・承認1(u0002)・承認2(u0003)。2025-04-01〜2026-01-01)。
 * 確認する人の入力はユーザーの id の並び(並びと重複は見ず、重複を除いて 0〜10 人)。
 */
final class NodeDeadlineConfirmSettingsTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use SavesNodeSettings;

    private const DEADLINE_RANGE = '処理期限は 0 から 99 までの日数を入力してください';

    private int $linear;

    protected function setUp(): void
    {
        parent::setUp();
        $this->addVersion($this->r1());
        $this->linear = WorkflowFlowVersion::query()->whereRelation('workflowFlow', 'code', self::FLOW_CODE)
            ->where('number', 1)->sole()->id;
    }

    /**
     * 期限の日数の境界: 0・99 は通り、空欄(null)は NULL。-1・100 は 422 で何も変わらない(小数・文字は入口の型の誤り)。
     */
    public function test_the_deadline_days_at_the_boundaries(): void
    {
        foreach ([0, 99, null] as $days) {
            $this->saveSettings($this->linear, 'approval1', ['deadlineDays' => $days]);
            $this->assertSame($days, $this->routeNode($this->linear, 'approval1')->deadline_days);
        }

        foreach ([-1, 100] as $days) {
            $this->assertErrors(['deadline_days' => self::DEADLINE_RANGE], ['deadlineDays' => $days]);
        }
    }

    /**
     * 確認する人の保存は fixed の対象者と同じ規則: 同じ集合(並びが違う・重複を含む)なら作らず、違えば作って指し直し、前の対象者は残る。
     * 0 人で NULL。作った対象者の表示名 =「{フローの名前} / {ノードの名前} / 確認する人」。
     */
    public function test_the_confirmers_keep_the_same_set_and_repoint_a_different_set(): void
    {
        $groups = AuthzSubjectGroup::query()->count();
        $this->saveSettings($this->linear, 'approval1', ['confirmers' => $this->userIds('u0002', 'u0003')]);
        $first = (int) $this->confirmGroupOf('approval1');
        $this->assertSame($groups + 1, AuthzSubjectGroup::query()->count());
        $this->assertEquals([$this->userCondition('u0002'), $this->userCondition('u0003')], $this->targetsOf($first));
        $names = AuthzSubjectGroupTranslation::query()->where('authz_subject_group_id', $first)
            ->pluck('display_name')->all();
        $this->assertSame(array_fill(0, Locale::query()->count(), 'プロジェクト計画の承認 / 承認1 / 確認する人'), $names);

        $this->saveSettings($this->linear, 'approval1', ['confirmers' => $this->userIds('u0003', 'u0002', 'u0003')]);
        $this->assertSame(
            [$first, $groups + 1],
            [$this->confirmGroupOf('approval1'), AuthzSubjectGroup::query()->count()],
        );

        $this->saveSettings($this->linear, 'approval1', ['confirmers' => $this->userIds('u0004')]);
        $second = $this->confirmGroupOf('approval1');
        $this->assertNotSame($first, $second);
        $this->assertTrue(AuthzSubjectGroup::query()->whereKey($first)->exists());

        $this->saveSettings($this->linear, 'apply', ['confirmers' => $this->userIds('u0005')]);
        $this->assertEquals([$this->userCondition('u0005')], $this->targetsOf((int) $this->confirmGroupOf('apply')));

        $this->saveSettings($this->linear, 'approval1', ['confirmers' => []]);
        $this->assertNull($this->routeNode($this->linear, 'approval1')->confirm_authz_subject_group_id);
    }

    /**
     * 重複を除いて 10 人なら通り(重複を含めて 11 件の入力)、11 人は 422 で何も変わらない。
     */
    public function test_ten_confirmers_without_duplicates_pass_and_eleven_fail(): void
    {
        $codes = array_map(fn (int $number) => sprintf('u%04d', $number), range(1, 11));
        $ten = $this->userIds(...array_slice($codes, 0, 10));

        $this->saveSettings($this->linear, 'approval1', ['confirmers' => [...$ten, $ten[0]]]);
        $this->assertCount(10, $this->targetsOf((int) $this->confirmGroupOf('approval1')));

        $this->assertErrors(
            ['confirmers' => '確認する人は 10 人まで選択できます'],
            ['confirmers' => $this->userIds(...$codes)],
        );
    }

    /**
     * 存在しないユーザーは、その添字のキーの 422(ほかの欄の誤りと一緒に返る)。作った対象者も残らない。
     */
    public function test_a_missing_confirmer_is_rejected_by_its_index(): void
    {
        $this->assertErrors(
            ['confirmers.1' => '確認する人を選択してください', 'deadline_days' => self::DEADLINE_RANGE],
            ['confirmers' => [...$this->userIds('u0002'), 999_999_999], 'deadlineDays' => 100],
        );
        $this->assertErrors(
            ['deadline_days' => self::DEADLINE_RANGE],
            ['confirmers' => $this->userIds('u0002'), 'deadlineDays' => 100],
        );
    }

    /**
     * 保存の後の申請だけが新しい期限・確認する人を写し、進行中の案件の写しは変わらない。
     */
    public function test_only_applications_after_the_save_copy_the_deadline_and_the_confirmers(): void
    {
        $earlier = $this->apply();

        $this->saveSettings($this->linear, 'approval1', ['deadlineDays' => 4, 'confirmers' => $this->userIds('u0005')]);
        $later = $this->apply();

        $group = $this->confirmGroupOf('approval1');
        $copies = [$this->copiedApproval($earlier), $this->copiedApproval($later)];
        $this->assertSame([[null, null], [4, $group]], $copies);
    }

    /**
     * @param  array<string, string>  $expected
     * @param  array<string, mixed>  $overrides  版 1 の承認1 の入力に重ねる欄
     */
    private function assertErrors(array $expected, array $overrides): void
    {
        $before = $this->settingsSnapshot();
        $errors = $this->validationErrors(fn () => $this->saveSettings($this->linear, 'approval1', $overrides));

        ksort($expected);
        ksort($errors);
        $this->assertSame($expected, $errors);
        $this->assertSame($before, $this->settingsSnapshot());
    }

    /**
     * @return list<int>
     */
    private function userIds(string ...$codes): array
    {
        return array_map(fn (string $code) => $this->seededUser($code)->id, array_values($codes));
    }

    private function confirmGroupOf(string $code): ?int
    {
        $id = $this->routeNode($this->linear, $code)->confirm_authz_subject_group_id;

        return $id === null ? null : (int) $id;
    }

    /**
     * u0001 が申請基準日 2025-12-01(版 1 の期間)で申請した案件の id。
     */
    private function apply(): int
    {
        $context = $this->contextOf($this->seededUser('u0001'));

        return app(ApplyCase::class)->execute($context, self::FLOW_CODE, $this->projectInput(), '2025-12-01')->caseId;
    }

    /**
     * 案件が写した承認1: [期限の日数, 確認する人の対象者]。
     *
     * @return array{int|null, int|null}
     */
    private function copiedApproval(int $caseId): array
    {
        $node = WorkflowCaseNode::query()->where('workflow_case_id', $caseId)->where('code', 'approval1')->sole();
        $group = $node->confirm_authz_subject_group_id;

        return [$node->deadline_days, $group === null ? null : (int) $group];
    }
}
