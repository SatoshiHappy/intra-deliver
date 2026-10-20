<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Records\RecordField;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\TransactionalTestCase;

/**
 * 管理の画面の入口が is_multiple を業務処理に渡すこと(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.4 の入力の形、
 * 項目と規則/規則と業務処理/失敗と定義の変更.md 11 節)。システム管理者(u0004)で、新しく作った業務モジュールに項目を足す。
 */
final class MultipleReferenceAdminControllerTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    public function test_adds_a_multiple_field_and_refuses_to_change_it(): void
    {
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
        $this->post('/records/admin/modules', ['code' => 'equipment', 'name' => '備品'])->assertStatus(303);

        $input = ['code' => 'users', 'name' => '利用者', 'type' => 'user', 'is_multiple' => true];

        $this->post('/records/admin/modules/equipment/fields', $input)
            ->assertStatus(303)
            ->assertSessionHasNoErrors();
        $this->get('/records/admin/modules/equipment/fields/users/edit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('field.isMultiple', true));
        $this->put('/records/admin/modules/equipment/fields/users', ['is_multiple' => false])
            ->assertSessionHasErrors(['is_multiple' => '追加後は変更できません']);

        $this->assertTrue(RecordField::query()->where('code', 'users')->sole()->is_multiple);
    }
}
