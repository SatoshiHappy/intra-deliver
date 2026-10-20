<?php

namespace Tests\Feature\Http\Controllers\Web\Authz;

use App\Actions\Authz\ChangeAuthzCell;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Authz\AuthzSubjectGroup;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * セルの切替(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 2 節・5.1・8 節のセルと行): 6 通りの遷移、同じ値は何もしない、from の違い、
 * 相手の確かめ(404・422 のキー)、継承のセル、入力の形、ルートの認可。守りは {@see AuthzEditGuardTest}。
 * 初期データ(システム管理者 = u0004)を入れ、今日を 2026-10-10 に固定し、ルートの認可を外さずに送る。
 */
final class AuthzCellControllerTest extends TransactionalTestCase
{
    use EditsAuthz;

    private const ROW = 'http-services.master.search';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-10 03:00:00 UTC');
        $this->actingAs($this->user('u0004'));
    }

    #[TestWith(['none', 'permit'])]
    #[TestWith(['none', 'deny'])]
    #[TestWith(['permit', 'deny'])]
    #[TestWith(['deny', 'permit'])]
    #[TestWith(['permit', 'none'])]
    #[TestWith(['deny', 'none'])]
    public function test_changes_the_direct_setting_of_the_cell(string $from, string $to): void
    {
        $column = $this->userColumn('u0001');

        if ($from !== 'none') {
            $this->place(self::ROW, $column, PolicyEffect::from($from));
        }

        $this->changeCell(self::ROW, $column, $from, $to)
            ->assertRedirect(self::OVERVIEW_URL)
            ->assertSessionHasNoErrors();

        $this->assertSame($to, $this->directSetting(self::ROW, $column));
    }

    public function test_the_same_value_does_nothing(): void
    {
        $column = $this->userColumn('u0001');
        $this->place(self::ROW, $column, PolicyEffect::Permit);
        $policy = AuthzPolicy::query()->where('authz_subject_group_id', $column)->sole();

        $this->changeCell(self::ROW, $column, 'permit', 'permit')
            ->assertRedirect(self::OVERVIEW_URL)
            ->assertSessionHasNoErrors();

        $after = AuthzPolicy::query()->where('authz_subject_group_id', $column)->sole();
        $this->assertSame([$policy->id, PolicyEffect::Permit], [$after->id, $after->effect]);
    }

    public function test_rejects_a_from_other_than_the_current_direct_setting(): void
    {
        $column = $this->userColumn('u0001');
        $this->place(self::ROW, $column, PolicyEffect::Deny);

        $this->changeCell(self::ROW, $column, 'permit', 'none')
            ->assertSessionHasErrors(['cell' => ChangeAuthzCell::STALE_MESSAGE]);
        $this->changeCell(self::ROW, $column, 'none', 'permit')
            ->assertSessionHasErrors(['cell' => ChangeAuthzCell::STALE_MESSAGE]);

        $this->assertSame('deny', $this->directSetting(self::ROW, $column));
    }

    public function test_an_inherited_cell_is_none_and_pressing_it_places_a_direct_permit(): void
    {
        $administrator = $this->administratorColumn();

        $this->changeCell(self::ROW, $administrator, 'permit', 'deny')
            ->assertSessionHasErrors(['cell' => ChangeAuthzCell::STALE_MESSAGE]);
        $this->changeCell(self::ROW, $administrator, 'none', 'permit')->assertSessionHasNoErrors();

        $this->assertSame('permit', $this->directSetting(self::ROW, $administrator));
    }

    public function test_rejects_a_subject_for_selection(): void
    {
        $selection = AuthzSubjectGroup::query()->where('purpose', SubjectGroupPurpose::Selection)->firstOrFail();

        $this->changeCell(self::ROW, $selection->id, 'none', 'permit')->assertSessionHasErrors('subject_group_id');

        $this->assertSame('none', $this->directSetting(self::ROW, $selection->id));
    }

    public function test_rejects_a_group_that_is_not_a_row_of_the_type(): void
    {
        $column = $this->userColumn('u0001');

        $this->changeCell('im-menu-group', $column, 'none', 'permit')->assertSessionHasErrors('resource_group_id');

        $this->assertSame('none', $this->directSetting('im-menu-group', $column));
    }

    public function test_rejects_a_type_or_an_action_missing_from_the_registered_types(): void
    {
        $column = $this->userColumn('u0001');

        $this->changeCell(self::ROW, $column, 'none', 'permit', ['type' => 'no-such-type'])
            ->assertSessionHasErrors('type');
        $this->changeCell(self::ROW, $column, 'none', 'permit', ['action' => 'read'])->assertSessionHasErrors('action');

        $this->assertSame(0, AuthzPolicy::query()->where('authz_subject_group_id', $column)->count());
    }

    public function test_a_missing_group_or_subject_is_not_found(): void
    {
        $column = $this->userColumn('u0001');
        $missing = (int) AuthzSubjectGroup::query()->max('id') + 1000;

        $missingGroup = (int) AuthzResourceGroup::query()->max('id') + 1000;

        $this->changeCell(self::ROW, $column, 'none', 'permit', ['resource_group_id' => $missingGroup])
            ->assertNotFound();
        $this->changeCell(self::ROW, $missing, 'none', 'permit')->assertNotFound();
    }

    #[TestWith(['from', 'allow'])]
    #[TestWith(['to', ''])]
    #[TestWith(['resource_group_id', 'x'])]
    #[TestWith(['subject_group_id', '0'])]
    public function test_rejects_an_input_out_of_its_form_under_its_key(string $key, string $value): void
    {
        $this->changeCell(self::ROW, $this->userColumn('u0001'), 'none', 'permit', [$key => $value])
            ->assertSessionHasErrors($key);
    }

    /**
     * リソースの無いグループにポリシーを置くと、その種類の格子の行に出て、セルから消せる(5.1)。
     */
    public function test_a_group_without_resources_holding_a_policy_is_a_row_and_its_cell_can_be_cleared(): void
    {
        $column = $this->userColumn('u0001');
        $parentId = $this->groupId('http-services');
        AuthzResourceGroup::factory()->create(['code' => 'zz-no-resource', 'parent_id' => $parentId]);
        $this->assertNotContains('zz-no-resource', $this->rowCodes());
        $this->place('zz-no-resource', $column, PolicyEffect::Permit);

        $this->assertContains('zz-no-resource', $this->rowCodes());
        $this->changeCell('zz-no-resource', $column, 'permit', 'none')->assertSessionHasNoErrors();

        $this->assertSame('none', $this->directSetting('zz-no-resource', $column));
        $this->assertNotContains('zz-no-resource', $this->rowCodes());
    }

    public function test_a_guest_and_a_user_without_the_permission_are_forbidden(): void
    {
        $column = $this->userColumn('u0001');

        $this->actingAs($this->user('u0001'));
        $this->changeCell(self::ROW, $column, 'none', 'permit')->assertForbidden();
        auth()->logout();
        $this->changeCell(self::ROW, $column, 'none', 'permit')->assertForbidden();

        $this->assertSame('none', $this->directSetting(self::ROW, $column));
    }

    /**
     * 画面・処理の格子の行のコード。
     *
     * @return list<string>
     */
    private function rowCodes(): array
    {
        $this->withoutVite();

        return array_column($this->get(self::OVERVIEW_URL)->viewData('page')['props']['grid']['rows'], 'code');
    }
}
