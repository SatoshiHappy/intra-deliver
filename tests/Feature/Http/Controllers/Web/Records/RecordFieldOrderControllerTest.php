<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Records\RecordField;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 項目の並び替えの口 records.admin.fields.reorder(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/管理の画面.md 3.3、認可とメニュー/README.md 4 節、メニューと初期データ.md 8 節の「管理」、
 * 項目と規則/規則と業務処理/画面と検査.md 13 節の「ルート `reorder` が `{field}` に取られない」): ゲストと u0001 は 403 で並びが変わらず、
 * システム管理者(u0004)は並びが変わる。
 * 成功は定義の画面へ 303(トーストなし)、順列でない codes はキー codes のエラーで元の画面へ、業務モジュールが無ければ壊れた codes でも 404。
 * 項目の編集の画面は並び順を渡さない(画面/管理の画面.md 3.4)。初期データを入れ、ルートの認可を外さずに開く。
 */
final class RecordFieldOrderControllerTest extends TransactionalTestCase
{
    /** 初期データの業務モジュール project の項目の並び */
    private const PROJECT_FIELDS = ['name', 'department', 'manager', 'status', 'start_date', 'end_date', 'budget', 'summary', 'milestones'];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_the_guest_and_a_user_without_the_system_administrator_role_cannot_reorder(?string $userCode): void
    {
        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        $this->put('/records/admin/modules/project/fields/reorder', ['codes' => array_reverse(self::PROJECT_FIELDS)])->assertForbidden();

        $this->assertSame(self::PROJECT_FIELDS, $this->order('project'));
    }

    public function test_the_system_administrator_reorders_the_fields_and_returns_to_the_definition_without_a_toast(): void
    {
        $this->actingAs($this->user('u0004'));
        $codes = ['summary', ...array_diff(self::PROJECT_FIELDS, ['summary'])];

        $this->put('/records/admin/modules/project/fields/reorder', ['codes' => $codes])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/project')
            ->assertInertiaFlashMissing('toast');

        $this->assertSame($codes, $this->order('project'));
        $this->get('/records/admin/modules/project')->assertInertia(fn (Assert $page) => $page->where('module.fields.0.code', 'summary'));
        $this->put('/records/admin/modules/project_milestone/fields/reorder', ['codes' => ['is_done', 'effort', 'due_date', 'name']])->assertStatus(303);
        $this->assertSame(['is_done', 'effort', 'due_date', 'name'], $this->order('project_milestone'));
    }

    public function test_codes_that_are_not_a_permutation_return_to_the_definition_with_the_key_codes(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->from('/records/admin/modules/project')
            ->put('/records/admin/modules/project/fields/reorder', ['codes' => ['name', 'name']])
            ->assertRedirect('/records/admin/modules/project')
            ->assertSessionHasErrors(['codes' => '項目の並びが今の項目と一致しません。画面を再読み込みしてから再度操作してください']);
        $this->put('/records/admin/modules/project/fields/reorder', ['codes' => 'name'])
            ->assertSessionHasErrors(['codes' => '項目の並びの形が正しくありません']);

        $this->assertSame(self::PROJECT_FIELDS, $this->order('project'));
    }

    public function test_a_missing_module_is_not_found_before_the_codes_are_checked(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->put('/records/admin/modules/nothing/fields/reorder', ['codes' => 'x'])->assertNotFound();
    }

    public function test_the_reorder_route_is_not_taken_by_the_field_route(): void
    {
        $route = Route::getRoutes()->match(Request::create('/records/admin/modules/project/fields/reorder', 'PUT'));

        $this->assertSame('records.admin.fields.reorder', $route->getName());
    }

    public function test_the_field_form_does_not_carry_the_sort_key(): void
    {
        $this->actingAs($this->user('u0004'))->get('/records/admin/modules/project/fields/name/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('field.code', 'name')
            ->missing('field.sortKey')
        );
    }

    /**
     * 業務モジュールの項目のコード(並び順・コードの順)。
     *
     * @return array<int, string>
     */
    private function order(string $moduleCode): array
    {
        return RecordField::query()->whereRelation('recordModule', 'code', $moduleCode)->orderBy('sort_key')->orderBy('code')->get()->map(fn (RecordField $field): string => $field->code)->values()->all();
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
