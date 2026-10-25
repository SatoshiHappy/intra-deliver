<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Menu\Menu;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 業務データの入れ物の管理(docs の 設計/業務データの入れ物/画面/README.md 1.1・設計/業務データの入れ物/画面/管理の画面.md 3 節、認可とメニュー/README.md 4 節、メニューと初期データ.md 8 節の「管理」): ゲストと u0001 は管理の
 * ルートが 403、システム管理者(u0004)は開ける。業務モジュールの定義(値の件数)、項目の追加・編集・削除、規則の追加・更新・削除。
 * 成功は定義の画面へ 303 と成功のトースト、業務処理の 422(設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 3)は元の画面へ戻り入力の名前のキーにエラーが付く。
 * 初期データを入れ、今日を 2026-10-11 に固定し、ルートの認可を外さずに開く。
 */
final class RecordAdminControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    #[TestWith([null, 'GET', '/records/admin/modules'])]
    #[TestWith([null, 'GET', '/records/admin/modules/create'])]
    #[TestWith([null, 'POST', '/records/admin/modules'])]
    #[TestWith([null, 'GET', '/records/admin/modules/project'])]
    #[TestWith([null, 'GET', '/records/admin/modules/project/fields/create'])]
    #[TestWith([null, 'POST', '/records/admin/modules/project/fields'])]
    #[TestWith([null, 'GET', '/records/admin/modules/project/fields/name/edit'])]
    #[TestWith([null, 'PUT', '/records/admin/modules/project/fields/name'])]
    #[TestWith([null, 'DELETE', '/records/admin/modules/project/fields/budget'])]
    #[TestWith([null, 'POST', '/records/admin/modules/project/rules'])]
    #[TestWith([null, 'PUT', '/records/admin/modules/project/rules/1'])]
    #[TestWith([null, 'DELETE', '/records/admin/modules/project/rules/1'])]
    #[TestWith(['u0001', 'GET', '/records/admin/modules'])]
    #[TestWith(['u0001', 'POST', '/records/admin/modules'])]
    #[TestWith(['u0001', 'DELETE', '/records/admin/modules/project/fields/budget'])]
    public function test_the_guest_and_a_user_without_the_system_administrator_role_are_forbidden(?string $userCode, string $method, string $url): void
    {
        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        $fields = RecordField::query()->count();

        $this->call($method, $url, ['code' => 'equipment', 'name' => '備品'])->assertForbidden();

        $this->assertSame($fields, RecordField::query()->count());
        $this->assertFalse(RecordModule::query()->where('code', 'equipment')->exists());
    }

    public function test_the_definition_carries_the_parent_and_the_usage_of_each_field(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->get('/records/admin/modules/project')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Admin/ModuleShow')
            ->where('module.code', 'project')
            ->where('module.parent', null)
            ->where('module.isVisible', true)
            ->has('module.fields', 9)
            ->where('module.rules.0.kind', 'compare')
            // 案件の運用の見本の 2 件(WF-000013 = 終了日あり、WF-000014 = 終了日なし。どちらも概要とマイルストーンなし = 案件の運用/初期データ.md の 4.3)を足した数
            ->where('fieldUsage', ['name' => 14, 'department' => 14, 'manager' => 14, 'status' => 14, 'start_date' => 14, 'end_date' => 10, 'budget' => 14, 'summary' => 3, 'milestones' => 12])
            ->where('textMaxLengths', ['code' => 50, 'name' => 1000, 'description' => 4000])
        );
        $this->get('/records/admin/modules/project_milestone')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('module.parent', ['code' => 'project', 'name' => 'プロジェクト'])
            ->where('module.isVisible', null)
            ->where('fieldUsage.is_done', 12)
            ->where('fieldUsage.effort', 12)
        );
    }

    public function test_creates_a_module_and_returns_to_its_definition_and_rejects_a_bad_code_with_its_key(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->post('/records/admin/modules', ['code' => 'equipment', 'name' => ' 備品 ', 'description' => '', 'icon' => 'clipboard-list'])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/equipment')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを作成しました']);
        $this->assertSame('備品', RecordModule::query()->where('code', 'equipment')->sole()->name);
        $this->assertSame('clipboard-list', Menu::query()->where('code', 'global-nav-main-records-equipment')->sole()->icon_32);

        $this->from('/records/admin/modules/create')->post('/records/admin/modules', ['code' => 'create', 'name' => '作成'])
            ->assertRedirect('/records/admin/modules/create')
            ->assertSessionHasErrors(['code' => 'create は予約語のためコードに使用できません']);
        $this->from('/records/admin/modules/create')->post('/records/admin/modules', ['code' => 'tool', 'name' => '工具', 'icon' => 'house'])
            ->assertRedirect('/records/admin/modules/create')
            ->assertSessionHasErrors(['icon' => 'アイコンは一覧から選択してください']);
    }

    /**
     * 項目の削除は業務モジュールに持ち主のあるレコード(初期データの未完了の案件のレコード)があると拒まれるので、新しく作った業務モジュールで行う
     * (持ち主の規則 = docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 4 節)。
     */
    public function test_adds_edits_and_deletes_a_field_through_the_forms(): void
    {
        $this->actingAs($this->user('u0004'));
        $this->post('/records/admin/modules', ['code' => 'equipment', 'name' => '備品'])->assertStatus(303);

        $this->get('/records/admin/modules/equipment/fields/create')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Admin/FieldForm')
            ->where('module', ['code' => 'equipment', 'name' => '備品', 'isDetails' => false])->where('field', null)
            ->where('referableModules', [
                ['code' => 'equipment', 'name' => '備品'],
                ['code' => 'issue_resolution_application', 'name' => '課題の対応の申請'], ['code' => 'meeting', 'name' => '会議'],
                ['code' => 'project', 'name' => 'プロジェクト'], ['code' => 'project_issue', 'name' => '課題'],
                ['code' => 'project_plan_application', 'name' => 'プロジェクト計画の申請'],
            ])
            ->where('textMaxLengths', ['code' => 50, 'name' => 1000, 'description' => 4000])
            ->where('stringMaxLengths', ['singleLine' => 1000, 'multiline' => 4000, 'multilineMax' => 20000])
        );
        $this->post('/records/admin/modules/equipment/fields', ['code' => 'cause', 'name' => '原因', 'type' => 'choice', 'is_listed' => true, 'options' => [['code' => 'spec', 'name' => '仕様'], ['code' => 'bug', 'name' => '不具合']]])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/equipment')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '項目を追加しました']);

        $this->get('/records/admin/modules/equipment/fields/cause/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('field.code', 'cause')
            ->where('field.options', [['code' => 'spec', 'name' => '仕様'], ['code' => 'bug', 'name' => '不具合']])
        );
        $this->put('/records/admin/modules/equipment/fields/cause', ['name' => '原因の分類', 'default_value' => 'bug'])
            ->assertStatus(303)->assertInertiaFlash('toast', ['type' => 'success', 'message' => '項目を更新しました']);
        $this->assertSame(['原因の分類', 'bug'], [$this->field('equipment', 'cause')->name, $this->field('equipment', 'cause')->default_value]);

        $this->delete('/records/admin/modules/equipment/fields/cause')
            ->assertStatus(303)->assertRedirect('/records/admin/modules/equipment')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '項目を削除しました']);
        $this->assertFalse(RecordField::query()->where('code', 'cause')->exists());
    }

    public function test_the_field_form_carries_the_stored_max_length_attribute(): void
    {
        $this->actingAs($this->user('u0004'));
        $this->put('/records/admin/modules/project/fields/name', ['max_length' => 1000])->assertStatus(303);

        $this->get('/records/admin/modules/project/fields/name/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('field.maxLength', 1000)
            ->where('field.maxLengthAttribute', 1000)
        );
        $this->get('/records/admin/modules/project/fields/summary/edit')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('field.maxLength', 4000)
            ->where('field.maxLengthAttribute', null)
        );
    }

    public function test_the_definition_errors_return_to_the_form_with_the_names_of_the_input(): void
    {
        $this->actingAs($this->user('u0004'));
        $fields = RecordField::query()->count();

        $this->from('/records/admin/modules/project/fields/create')
            ->post('/records/admin/modules/project/fields', ['code' => 'title2', 'name' => '別名', 'type' => 'string', 'is_title' => true, 'options' => [['code' => 'a', 'name' => 'A']]])
            ->assertRedirect('/records/admin/modules/project/fields/create')
            ->assertSessionHasErrors(['options' => '選択肢を設定できるのは選択の項目だけです']);
        $this->from('/records/admin/modules/project/fields/name/edit')
            ->put('/records/admin/modules/project/fields/name', ['code' => 'title', 'max_length' => 0])
            ->assertRedirect('/records/admin/modules/project/fields/name/edit')
            ->assertSessionHasErrors(['code' => '追加後は変更できません']);

        $this->assertSame($fields, RecordField::query()->count());
    }

    public function test_deleting_a_field_used_by_a_rule_returns_to_the_definition_with_the_key_field(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->from('/records/admin/modules/project_issue')
            ->delete('/records/admin/modules/project_issue/fields/status')
            ->assertRedirect('/records/admin/modules/project_issue')
            ->assertSessionHasErrors(['field' => '状態を使用する規則があります。先に規則を削除してください']);

        $this->assertTrue(RecordField::query()->where('code', 'status')->whereRelation('recordModule', 'code', 'project_issue')->exists());
    }

    public function test_adds_updates_and_deletes_a_rule_and_returns_its_errors_with_the_keys_of_the_condition(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->post('/records/admin/modules/project_issue/rules', ['kind' => 'required_when', 'target' => 'assignee', 'condition' => ['all' => [['field' => 'status', 'op' => 'in', 'value' => ['in_progress', 'done']]]], 'message' => null])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/project_issue')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '規則を追加しました']);
        $rule = RecordRule::query()->latest('id')->firstOrFail();

        $this->put("/records/admin/modules/project_issue/rules/{$rule->id}", ['kind' => 'required_when', 'target' => 'due_date', 'condition' => ['all' => [['field' => 'priority', 'op' => 'eq', 'value' => 'high']]], 'message' => '優先度が高いときは期限を入力してください'])
            ->assertStatus(303)->assertInertiaFlash('toast', ['type' => 'success', 'message' => '規則を更新しました']);
        $this->assertSame('優先度が高いときは期限を入力してください', $rule->refresh()->message);

        $this->from('/records/admin/modules/project_issue')
            ->put("/records/admin/modules/project_issue/rules/{$rule->id}", ['kind' => 'required_when', 'target' => 'due_date', 'condition' => ['all' => [['field' => 'priority', 'op' => 'eq', 'value' => '  ']]]])
            ->assertRedirect('/records/admin/modules/project_issue')
            ->assertSessionHasErrors('condition.all.0.value');

        $this->delete("/records/admin/modules/project_issue/rules/{$rule->id}")
            ->assertStatus(303)->assertInertiaFlash('toast', ['type' => 'success', 'message' => '規則を削除しました']);
        $this->assertModelMissing($rule);
    }

    public function test_the_form_requests_check_only_the_containers_of_the_nested_input(): void
    {
        $this->actingAs($this->user('u0004'));
        $fields = RecordField::query()->count();
        $rules = RecordRule::query()->count();

        $this->post('/records/admin/modules/project/fields', ['code' => 'kind', 'name' => '種類', 'type' => 'choice', 'options' => 'x'])
            ->assertSessionHasErrors(['options' => '選択肢の形が正しくありません']);
        $this->post('/records/admin/modules/project/fields', ['code' => 'kind', 'name' => '種類', 'type' => 'choice', 'options' => ['x']])
            ->assertSessionHasErrors(['options.0' => '選択肢の形が正しくありません']);
        $this->post('/records/admin/modules/project/fields', ['code' => 'rows', 'name' => '行', 'type' => 'details', 'child_module' => 'x'])
            ->assertSessionHasErrors(['child_module' => '明細のモジュールの形が正しくありません']);
        $this->post('/records/admin/modules/project/rules', ['kind' => 'required_when', 'target' => 'summary', 'condition' => 'x'])
            ->assertSessionHasErrors(['condition' => '条件の形が正しくありません']);

        $this->assertSame([$fields, $rules], [RecordField::query()->count(), RecordRule::query()->count()]);
    }

    /**
     * 入力が正しくても誤っていても、対象が無ければ入力の検査より先に 404 になる(誤った入力の行は 302/422 にならないことで順序を見る)。
     *
     * @param  array<string, mixed>  $input
     */
    #[TestWith(['GET', '/records/admin/modules/nothing'])]
    #[TestWith(['GET', '/records/admin/modules/nothing/fields/create'])]
    #[TestWith(['POST', '/records/admin/modules/nothing/fields'])]
    #[TestWith(['GET', '/records/admin/modules/project/fields/nothing/edit'])]
    #[TestWith(['PUT', '/records/admin/modules/project/fields/nothing'])]
    #[TestWith(['DELETE', '/records/admin/modules/project/fields/nothing'])]
    #[TestWith(['POST', '/records/admin/modules/nothing/rules'])]
    #[TestWith(['PUT', '/records/admin/modules/project/rules/999999'])]
    #[TestWith(['DELETE', '/records/admin/modules/project/rules/999999'])]
    #[TestWith(['POST', '/records/admin/modules/nothing/fields', ['options' => 'x']])]
    #[TestWith(['PUT', '/records/admin/modules/project/fields/nothing', ['options' => 'x']])]
    #[TestWith(['PUT', '/records/admin/modules/project/rules/999999', ['condition' => 'x']])]
    public function test_a_missing_module_field_or_rule_is_not_found(string $method, string $url, array $input = ['name' => '名前']): void
    {
        $this->actingAs($this->user('u0004'))->call($method, $url, $input)->assertNotFound();
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }

    private function field(string $moduleCode, string $code): RecordField
    {
        return RecordField::query()->where('code', $code)->whereRelation('recordModule', 'code', $moduleCode)->sole();
    }
}
