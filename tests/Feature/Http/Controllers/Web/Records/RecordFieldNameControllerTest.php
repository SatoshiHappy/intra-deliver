<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Records\RecordField;
use Carbon\CarbonImmutable;
use Tests\Support\TransactionalTestCase;

/**
 * 項目の名前の重複の禁止の画面側(docs の 設計/業務データの入れ物/項目と規則/README.md 1 節。業務処理の検証は RecordFieldNameUniquenessTest): 同じ業務モジュールとその
 * 明細のモジュールの項目と同じ名前を、追加でも変更でも付けると、元の画面へ戻り、入力の名前のキー name にエラーが付く(何も書かない)。システム管理者(u0004)で開く。
 */
final class RecordFieldNameControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
    }

    public function test_adding_a_name_of_the_details_module_to_the_parent_returns_to_the_form_with_the_key_name(): void
    {
        $fields = RecordField::query()->count();

        $this->from('/records/admin/modules/project/fields/create')
            ->post('/records/admin/modules/project/fields', ['code' => 'name2', 'name' => '名称', 'type' => 'string'])
            ->assertRedirect('/records/admin/modules/project/fields/create')
            ->assertSessionHasErrors(['name' => 'この名前は「マイルストーン」の項目で既に使用されています']);

        $this->assertSame($fields, RecordField::query()->count());
    }

    public function test_renaming_a_details_field_to_a_name_of_the_parent_returns_to_the_form_with_the_key_name(): void
    {
        $this->from('/records/admin/modules/project_milestone/fields/due_date/edit')
            ->put('/records/admin/modules/project_milestone/fields/due_date', ['name' => 'プロジェクト名'])
            ->assertRedirect('/records/admin/modules/project_milestone/fields/due_date/edit')
            ->assertSessionHasErrors(['name' => 'この名前は「プロジェクト」の項目で既に使用されています']);

        $this->assertSame('期日', RecordField::query()->where('code', 'due_date')->whereRelation('recordModule', 'code', 'project_milestone')->sole()->name);
    }

    public function test_the_same_name_in_another_module_is_added(): void
    {
        $this->from('/records/admin/modules/project_issue/fields/create')
            ->post('/records/admin/modules/project_issue/fields', ['code' => 'owner_name', 'name' => 'プロジェクト名', 'type' => 'string'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertTrue(RecordField::query()->where('code', 'owner_name')->exists());
    }
}
