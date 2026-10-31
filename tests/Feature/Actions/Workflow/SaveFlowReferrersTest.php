<?php

namespace Tests\Feature\Actions\Workflow;

use App\Actions\Authz\Api\SelectionCondition;
use App\Actions\Workflow\SaveFlowReferrers;
use App\Enums\Authz\SelectionConditionKind;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\AuthzSubjectGroupTranslation;
use App\Models\Master\Locale;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SelectionSubjects;
use App\Queries\Workflow\CaseVisibility;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\RunsWorkflowOperations;
use Tests\Support\TransactionalTestCase;

/**
 * 参照者の指定の保存(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 5.2・8 節の参照者のテスト)。テストのフローの参照者 = 経理部(u0010 が属する)。
 * 期待の文言は 6 節の表から書いた。
 */
final class SaveFlowReferrersTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use RunsWorkflowOperations;

    private int $initialGroupId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createFlow();
        $this->initialGroupId = (int) $this->referrerGroupId();
    }

    public function test_no_rows_clear_the_referrers_and_keep_the_previous_subject_group(): void
    {
        $this->save([]);

        $this->assertNull($this->referrerGroupId());
        $this->assertTrue(AuthzSubjectGroup::query()->whereKey($this->initialGroupId)->exists());
    }

    /**
     * 条件が同じなら作らず、今の対象者が組み方 any・比較 eq でなければ作る(定義の管理.md の 5.1 節・5.2 節)。
     */
    public function test_the_same_set_keeps_the_subject_group_unless_it_is_not_any_of_eq(): void
    {
        $groups = AuthzSubjectGroup::query()->count();
        $this->save([$this->accounting(), $this->accounting()]);
        $this->assertSame([$this->initialGroupId, $groups], $this->referrerState());

        AuthzSubjectCondition::query()->where('authz_subject_group_id', $this->initialGroupId)
            ->update(['operator' => 'ge']);
        $this->save([$this->accounting()]);
        $byOperator = $this->referrerGroupId();
        $this->assertNotSame($this->initialGroupId, $byOperator);

        AuthzSubjectGroup::query()->whereKey($byOperator)->update(['combination' => 'all']);
        $this->save([$this->accounting()]);
        $this->assertNotContains($this->referrerGroupId(), [$this->initialGroupId, $byOperator]);
    }

    /**
     * 条件が違えば作って指し直し、前の対象者は残る。表示名と重複の入力(定義の管理.md の 5.1 節・5.2 節)。
     */
    public function test_a_different_set_creates_a_subject_group_and_repoints(): void
    {
        $u0003 = new SelectionCondition(SelectionConditionKind::User, $this->seededUser('u0003')->id);
        $this->save([$u0003, $this->accounting(), $u0003]);

        $groupId = (int) $this->referrerGroupId();
        $this->assertNotSame($this->initialGroupId, $groupId);
        $this->assertTrue(AuthzSubjectGroup::query()->whereKey($this->initialGroupId)->exists());
        $expected = [['user', $u0003->id], ['department', $this->accounting()->id]];
        $this->assertSame($expected, $this->conditionsOf($groupId));
        $this->assertSame($this->forEveryLocale('プロジェクト計画の承認 / 参照者'), $this->displayNames($groupId));
    }

    /**
     * 参照者が無いフローに行を保存すると作って指す。表示名の切り詰め(1001 文字。定義の管理.md の 5.1 節)。
     */
    public function test_a_flow_without_referrers_gets_a_subject_group_with_a_truncated_name(): void
    {
        $this->save([]);
        WorkflowFlow::query()->where('code', self::FLOW_CODE)->update(['name' => str_repeat('あ', 995)]);

        $this->save([$this->accounting()]);

        $groupId = (int) $this->referrerGroupId();
        $this->assertSame([['department', $this->accounting()->id]], $this->conditionsOf($groupId));
        $this->assertSame($this->forEveryLocale(str_repeat('あ', 995).' / 参照'), $this->displayNames($groupId));
    }

    /**
     * 相手が存在しない行は 422 referrers.<入力の添字>で、何も変わらない(定義の管理.md の 6 節)。
     */
    public function test_a_missing_target_is_rejected_with_the_input_index_and_nothing_changes(): void
    {
        $groups = AuthzSubjectGroup::query()->count();
        $missing = new SelectionCondition(SelectionConditionKind::User, 999_999_999);

        $rows = [$this->accounting(), $this->accounting(), $missing, $missing];
        $errors = $this->validationErrors(fn () => $this->save($rows));

        $this->assertSame(['referrers.2' => '対象者を選択してください'], $errors);
        $this->assertSame([$this->initialGroupId, $groups], $this->referrerState());
    }

    /**
     * 保存の後の閲覧から参照のタブが変わり、進行中・完了の案件にも効く。
     */
    public function test_the_referenced_tab_follows_the_saved_referrers_for_active_and_completed_cases(): void
    {
        $flowId = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->id;
        $attributes = ['workflow_flow_id' => $flowId, 'user_id' => $this->seededUser('u0001')->id];
        $cases = [
            WorkflowCase::factory()->active()->createOne($attributes)->id,
            WorkflowCase::factory()->completed()->createOne($attributes)->id,
        ];
        sort($cases);
        $this->assertSame([$cases, []], [$this->referencedBy('u0010', $cases), $this->referencedBy('u0003', $cases)]);

        $this->save([new SelectionCondition(SelectionConditionKind::User, $this->seededUser('u0003')->id)]);
        $this->assertSame([[], $cases], [$this->referencedBy('u0010', $cases), $this->referencedBy('u0003', $cases)]);

        $this->save([]);
        $this->assertSame([[], []], [$this->referencedBy('u0010', $cases), $this->referencedBy('u0003', $cases)]);
    }

    public function test_a_missing_flow_is_not_found(): void
    {
        $this->assertThrows(
            fn () => app(SaveFlowReferrers::class)->execute('no_such_flow', []),
            ModelNotFoundException::class,
        );
    }

    /**
     * @param  list<SelectionCondition>  $referrers
     */
    private function save(array $referrers): void
    {
        app(SaveFlowReferrers::class)->execute(self::FLOW_CODE, $referrers);
    }

    private function accounting(): SelectionCondition
    {
        return new SelectionCondition(SelectionConditionKind::Department, $this->departmentIdOf('accounting'));
    }

    private function referrerGroupId(): ?int
    {
        $id = WorkflowFlow::query()->where('code', self::FLOW_CODE)->sole()->authz_subject_group_id;

        return $id === null ? null : (int) $id;
    }

    /**
     * @return list<array{string, int}> [種類, 相手の id](作った順)
     */
    private function conditionsOf(int $groupId): array
    {
        $groups = app(SelectionSubjects::class)
            ->conditionsOf([$groupId], $this->operationDay(), AccessContext::LOCALE_CODE);

        return array_map(
            fn (array $condition) => [$condition['kind']->value, $condition['id']],
            $groups[$groupId]['conditions'],
        );
    }

    /**
     * @return list<string> ロケールごとの表示名(ロケールの id の順)
     */
    private function displayNames(int $groupId): array
    {
        $names = AuthzSubjectGroupTranslation::query()->where('authz_subject_group_id', $groupId)
            ->orderBy('locale_id')->pluck('display_name')->all();

        return array_values(array_map('strval', $names));
    }

    /**
     * @return list<string> ロケールの数だけ並べた表示名
     */
    private function forEveryLocale(string $name): array
    {
        return array_fill(0, Locale::query()->count(), $name);
    }

    /**
     * @return array{int|null, int} [フローの参照者の対象者, 対象者の数]
     */
    private function referrerState(): array
    {
        return [$this->referrerGroupId(), AuthzSubjectGroup::query()->count()];
    }

    /**
     * 参照の述語で絞った案件の id(昇順。基準日 = 操作の基準日、カレント組織 = 主所属)。
     *
     * @param  list<int>  $caseIds
     * @return list<int>
     */
    private function referencedBy(string $userCode, array $caseIds): array
    {
        $requester = new Requester($this->seededUser($userCode)->id, null, null);
        $query = app(CaseVisibility::class)
            ->referenced(WorkflowCase::query()->whereKey($caseIds), $requester, $this->operationDay());

        return array_values(array_map('intval', $query->orderBy('id')->pluck('id')->all()));
    }
}
