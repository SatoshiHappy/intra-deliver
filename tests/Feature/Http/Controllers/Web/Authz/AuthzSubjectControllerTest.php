<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Actions\Authz\CreateSubjectGroup;
use App\Actions\Authz\DeleteAuthzColumn;
use App\Actions\Authz\SubjectConditionInput;
use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Queries\Authz\AuthzEditGuard;
use App\Queries\Authz\SubjectGroupPolicyFingerprints;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Testing\TestResponse;
use LogicException;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\TransactionalTestCase;

/**
 * 列(対象者)の追加と削除(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 3 節・8 節の列): 4 種の追加・同じ条件・今日に有効でない相手、
 * 作った列のセル、組み込みの列・子に使われている列・件数と指紋の違い、すべての種類のポリシーの削除、守りに当たる削除、ルートの認可。
 * 初期データ(システム管理者 = u0004、退職者 u0014、入社前の u0015)を入れ、今日を 2026-10-10 に固定する。
 */
final class AuthzSubjectControllerTest extends TransactionalTestCase
{
    use EditsAuthz;

    private const SUBJECTS_URL = '/authz/editor/subjects';

    private const CANDIDATES_URL = '/authz/editor/subject-candidates';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->actingAs($this->user('u0004'));
    }

    #[TestWith(['user', 'u0001', 'user_id', '佐々木 陽太'])]
    #[TestWith(['role', 'master_admin', 'role_id', '共通マスタ管理者'])]
    #[TestWith(['department', 'tokyo-sales', 'department_id', '東京営業課'])]
    #[TestWith(['post', 'manager', 'company_post_id', '部長'])]
    public function test_adds_a_column_with_one_condition_named_after_the_target_and_no_settings(
        string $kind,
        string $code,
        string $column,
        string $name,
    ): void {
        $targetId = $this->targetId($kind, $code);

        $this->addColumn($kind, $targetId)->assertRedirect(self::OVERVIEW_URL)->assertSessionHasNoErrors();

        $subjectGroup = AuthzSubjectGroup::query()
            ->whereHas('authzSubjectConditions', fn (Builder $conditions) => $conditions->where($column, $targetId))
            ->where('purpose', SubjectGroupPurpose::Policy)
            ->with('authzSubjectConditions', 'authzSubjectGroupTranslations.locale')->sole();
        $condition = $subjectGroup->authzSubjectConditions->sole();
        $this->assertSame(SubjectCombination::All, $subjectGroup->combination);
        $this->assertSame([ConditionOperator::Equal, false], [$condition->operator, $condition->is_negated]);
        $translation = $subjectGroup->authzSubjectGroupTranslations->firstWhere('locale.code', 'ja');
        $this->assertSame($name, $translation?->display_name);
        $this->assertSame(0, AuthzPolicy::query()->where('authz_subject_group_id', $subjectGroup->id)->count());
    }

    public function test_rejects_a_column_with_the_same_condition(): void
    {
        $this->addColumn('user', $this->user('u0001')->id)->assertSessionHasNoErrors();
        $columns = AuthzSubjectGroup::query()->count();

        $this->addColumn('user', $this->user('u0001')->id)
            ->assertSessionHasErrors(['target_id' => '同じ条件の列がすでにあります']);
        $this->addColumn('role', Role::query()->where('code', 'system_admin')->sole()->id)
            ->assertSessionHasErrors('target_id');

        $this->assertSame($columns, AuthzSubjectGroup::query()->count());
    }

    #[TestWith(['u0014'])]
    #[TestWith(['u0015'])]
    public function test_rejects_a_target_not_effective_today(string $userCode): void
    {
        $this->addColumn('user', $this->user($userCode)->id)->assertSessionHasErrors('target_id');
        $this->addColumn('user', 99999999)->assertSessionHasErrors('target_id');
        $this->addColumn('group', 1)->assertSessionHasErrors('kind');
    }

    /**
     * 初期データに無い種類(役職)の列の束は、組織の束の右に並ぶ(5.2。認証 → ロール → ユーザー → 組織 → 役職)。
     */
    public function test_the_bundle_of_a_new_kind_is_placed_by_the_order_of_the_categories(): void
    {
        $this->addColumn('post', $this->targetId('post', 'manager'));
        $this->addColumn('department', $this->targetId('department', 'tokyo-sales'));
        $this->addColumn('user', $this->user('u0001')->id);
        $this->withoutVite();

        $subjects = $this->get(self::OVERVIEW_URL)->viewData('page')['props']['grid']['subjects'];

        $this->assertSame([
            'im_authz_meta_subject',
            'im_authz_meta_subject',
            'b_m_role',
            'imm_user',
            'imm_department',
            'imm_company_post',
        ], array_map(fn (array $subject) => $subject['category']['code'], $subjects));
    }

    /**
     * @param  list<string>  $codes
     */
    #[TestWith(['user', 'u001', ['u0010', 'u0011', 'u0012', 'u0013', 'u0016', 'u0017']])]
    #[TestWith(['role', '管理者', ['system_admin', 'master_admin', 'workflow_admin']])]
    #[TestWith(['post', '長', ['president', 'division-head', 'manager', 'assistant-manager', 'section-chief', 'chief']])]
    #[TestWith(['department', '営業', ['sales', 'sales-1', 'tokyo-sales', 'sales-2']])]
    public function test_the_candidates_are_the_targets_effective_today(
        string $kind,
        string $keyword,
        array $codes,
    ): void {
        $response = $this->getJson(self::CANDIDATES_URL.'?'.http_build_query(['kind' => $kind, 'q' => $keyword]))
            ->assertOk();

        $this->assertSame($codes, array_column($response->json('candidates'), 'code'));
        $this->assertSame(['id', 'code', 'name', 'mainDepartmentName'], array_keys($response->json('candidates.0')));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_the_candidates_reject_a_word_that_is_not_valid_utf8(): void
    {
        $this->getJson(self::CANDIDATES_URL.'?kind=user&q=%FF')->assertUnprocessable()
            ->assertJsonValidationErrors(['q' => '検索する語の指定が正しくありません']);
    }

    public function test_the_candidates_reject_an_unknown_kind_and_are_forbidden_without_the_permission(): void
    {
        $this->getJson(self::CANDIDATES_URL.'?kind=group&q=a')->assertUnprocessable()
            ->assertJsonValidationErrors('kind');

        $this->actingAs($this->user('u0001'));
        $this->getJson(self::CANDIDATES_URL.'?kind=user&q=u00')->assertForbidden();
        $this->addColumn('user', $this->user('u0002')->id)->assertForbidden();
    }

    public function test_deletes_every_policy_of_the_column_in_every_type_and_the_column(): void
    {
        $column = $this->userColumn('u0001');
        $this->place('http-services.master.search', $column, PolicyEffect::Permit);
        $this->place('im-menu-group-global-nav-admin', $column, PolicyEffect::Deny, 'im-menu-group', 'read');

        $this->deleteColumn($column)->assertRedirect(self::OVERVIEW_URL)->assertSessionHasNoErrors();

        $this->assertFalse(AuthzSubjectGroup::query()->whereKey($column)->exists());
        $this->assertSame(0, AuthzPolicy::query()->where('authz_subject_group_id', $column)->count());
        $this->assertSame(0, AuthzSubjectCondition::query()->where('authz_subject_group_id', $column)->count());
        $this->withoutVite();
        $menuSubjects = $this->get(self::OVERVIEW_URL.'?type=im-menu-group')
            ->viewData('page')['props']['grid']['subjects'];
        $this->assertNotContains($column, array_column($menuSubjects, 'id'));
    }

    public function test_rejects_deleting_a_builtin_column(): void
    {
        foreach ([$this->administratorColumn(), $this->guestColumn(), $this->authenticatedColumn()] as $column) {
            $this->deleteColumn($column)->assertSessionHasErrors(['subject' => 'この列は削除できません。']);
            $this->assertTrue(AuthzSubjectGroup::query()->whereKey($column)->exists());
        }
    }

    public function test_rejects_deleting_a_column_used_by_another_subject(): void
    {
        $column = $this->userColumn('u0001');
        $child = new SubjectConditionInput(childSubjectGroupId: $column);
        app(CreateSubjectGroup::class)->execute(SubjectCombination::All, [$child], ['ja' => ['display_name' => '親']]);

        $this->deleteColumn($column)
            ->assertSessionHasErrors(['subject' => 'ほかの対象者の条件に使われているため、削除できません。']);

        $this->assertTrue(AuthzSubjectGroup::query()->whereKey($column)->exists());
    }

    public function test_rejects_a_count_or_a_fingerprint_other_than_the_current_one(): void
    {
        $column = $this->userColumn('u0001');
        $seen = app(SubjectGroupPolicyFingerprints::class)->of([$column])[$column];
        $this->place('http-services.master.search', $column, PolicyEffect::Permit);

        $this->deleteColumn($column, $seen)->assertSessionHasErrors(['subject' => DeleteAuthzColumn::STALE_MESSAGE]);
        $current = app(SubjectGroupPolicyFingerprints::class)->of([$column])[$column];
        $this->deleteColumn($column, ['policyCount' => $current['policyCount'], 'fingerprint' => $seen['fingerprint']])
            ->assertSessionHasErrors('subject');
        $this->deleteColumn($column, ['policyCount' => 0, 'fingerprint' => $current['fingerprint']])
            ->assertSessionHasErrors('subject');

        $this->assertSame('permit', $this->directSetting('http-services.master.search', $column));
    }

    public function test_deleting_the_column_that_lets_the_operator_open_and_save_is_rolled_back(): void
    {
        $column = $this->userColumn('u0001');
        $this->place('http-services.authz.overview', $column, PolicyEffect::Permit);
        $this->place('http-services.authz.editor', $column, PolicyEffect::Permit);
        $this->actingAs($this->user('u0001'));

        $this->deleteColumn($column)->assertSessionHasErrors(['subject' => AuthzEditGuard::VIOLATION_MESSAGE]);

        $this->assertSame(2, AuthzPolicy::query()->where('authz_subject_group_id', $column)->count());
    }

    public function test_a_subject_for_selection_or_a_missing_subject_is_not_found(): void
    {
        $selection = AuthzSubjectGroup::query()->where('purpose', SubjectGroupPurpose::Selection)->firstOrFail();

        $this->deleteColumn($selection->id)->assertNotFound();
        $this->deleteColumn((int) AuthzSubjectGroup::query()->max('id') + 1000)->assertNotFound();
    }

    public function test_rejects_an_input_out_of_its_form(): void
    {
        $column = $this->userColumn('u0001');

        $this->deleteColumn($column, ['policyCount' => -1, 'fingerprint' => str_repeat('a', 64)])
            ->assertSessionHasErrors('count');
        $this->deleteColumn($column, ['policyCount' => 0, 'fingerprint' => 'abc'])
            ->assertSessionHasErrors('fingerprint');
    }

    private function targetId(string $kind, string $code): int
    {
        return match ($kind) {
            'user' => $this->user($code)->id,
            'role' => Role::query()->where('code', $code)->sole()->id,
            'department' => Department::query()->where('code', $code)->sole()->id,
            'post' => CompanyPost::query()->where('code', $code)->sole()->id,
            default => throw new LogicException("種類の誤り: {$kind}"),
        };
    }

    /**
     * @return TestResponse<Response>
     */
    private function addColumn(string $kind, int $targetId): TestResponse
    {
        return $this->from(self::OVERVIEW_URL)->post(self::SUBJECTS_URL, ['kind' => $kind, 'target_id' => $targetId]);
    }

    /**
     * 列を削除する(件数と指紋を渡さなければ、今の値を送る)。
     *
     * @param  array{policyCount: int, fingerprint: string}|null  $seen
     * @return TestResponse<Response>
     */
    private function deleteColumn(int $subjectGroupId, ?array $seen = null): TestResponse
    {
        $seen ??= app(SubjectGroupPolicyFingerprints::class)->of([$subjectGroupId])[$subjectGroupId];

        return $this->from(self::OVERVIEW_URL)->delete(self::SUBJECTS_URL."/{$subjectGroupId}", [
            'count' => $seen['policyCount'],
            'fingerprint' => $seen['fingerprint'],
        ]);
    }
}
