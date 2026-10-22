<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Records\Record;
use App\Models\Records\RecordFieldHeading;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 見出しの管理のルート records.admin.headings.*(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の
 * 5 節・6 節・7 節の「見出し」「認可」、詳細の画面/見出し.md の 4 節・5 節): ゲストとシステム管理者のロールを持たない人は 403、
 * 成功は定義の画面へ 303 とトースト、名前の誤りは入力の名前のキー name で元の画面へ、404 は 422 より先、
 * 明細のモジュールには足せない(キー module)。定義の画面の props に見出しと 1 本の並び(headings・items)が入る。
 * 初期データの「プロジェクト」(見本の見出し 2 つ)を使う。
 */
final class RecordHeadingControllerTest extends TransactionalTestCase
{
    private const DEFINITION = '/records/admin/modules/project';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    #[TestWith([null, 'POST', '/records/admin/modules/project/headings'])]
    #[TestWith([null, 'PUT', '/records/admin/modules/project/headings/1'])]
    #[TestWith([null, 'DELETE', '/records/admin/modules/project/headings/1'])]
    #[TestWith(['u0001', 'POST', '/records/admin/modules/project/headings'])]
    #[TestWith(['u0001', 'PUT', '/records/admin/modules/project/headings/1'])]
    #[TestWith(['u0001', 'DELETE', '/records/admin/modules/project/headings/1'])]
    public function test_the_guest_and_a_user_without_the_administrator_role_are_forbidden(
        ?string $userCode,
        string $method,
        string $url,
    ): void {
        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        $before = $this->headingRows();

        $this->call($method, $url, ['name' => '見出し'])->assertForbidden();

        $this->assertSame($before, $this->headingRows());
    }

    public function test_the_administrator_adds_updates_and_deletes_a_heading_with_a_toast(): void
    {
        $this->actingAs($this->user('u0004'));
        $issue = '/records/admin/modules/project_issue';

        $this->post("{$issue}/headings", ['name' => '対応'])
            ->assertStatus(303)->assertRedirect($issue)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '見出しを追加しました']);
        $heading = RecordFieldHeading::query()->where('name', '対応')->sole();
        $this->put("{$issue}/headings/{$heading->id}", ['name' => '対応の状況'])
            ->assertStatus(303)->assertRedirect($issue)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '見出しを更新しました']);
        $this->assertSame('対応の状況', $heading->refresh()->name);
        $this->delete("{$issue}/headings/{$heading->id}")
            ->assertStatus(303)->assertRedirect($issue)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '見出しを削除しました']);

        $this->assertFalse(RecordFieldHeading::query()->whereKey($heading->id)->exists());
    }

    public function test_a_bad_name_returns_with_the_key_name_and_a_details_module_with_the_key_module(): void
    {
        $this->actingAs($this->user('u0004'));
        $heading = RecordFieldHeading::query()->orderBy('id')->firstOrFail();
        $before = $this->headingRows();

        $this->from(self::DEFINITION)->post(self::DEFINITION.'/headings', ['name' => ''])
            ->assertRedirect(self::DEFINITION)
            ->assertSessionHasErrors(['name' => '見出しの名前を入力してください']);
        $this->put(self::DEFINITION."/headings/{$heading->id}", ['name' => str_repeat('あ', 1001)])
            ->assertSessionHasErrors(['name' => '見出しの名前は 1000 文字までです']);
        $this->post('/records/admin/modules/project_milestone/headings', ['name' => '見出し'])
            ->assertSessionHasErrors(['module' => '明細には見出しを追加できません']);

        $this->assertSame($before, $this->headingRows());
    }

    #[TestWith(['POST', '/records/admin/modules/nothing/headings'])]
    #[TestWith(['PUT', '/records/admin/modules/nothing/headings/1'])]
    #[TestWith(['PUT', '/records/admin/modules/project/headings/999999'])]
    #[TestWith(['DELETE', '/records/admin/modules/project/headings/999999'])]
    #[TestWith(['PUT', '/records/admin/modules/project/headings/abc'])]
    public function test_a_missing_module_or_heading_is_not_found_before_the_name_is_checked(
        string $method,
        string $url,
    ): void {
        $this->actingAs($this->user('u0004'))->call($method, $url, ['name' => ''])->assertNotFound();
    }

    public function test_another_modules_heading_is_not_found(): void
    {
        $this->actingAs($this->user('u0004'));
        $heading = RecordFieldHeading::query()->whereRelation('recordModule', 'code', 'project')->firstOrFail();
        $other = "/records/admin/modules/project_issue/headings/{$heading->id}";

        $this->put($other, ['name' => ''])->assertNotFound();
        $this->delete($other)->assertNotFound();

        $this->assertTrue(RecordFieldHeading::query()->whereKey($heading->id)->exists());
    }

    public function test_the_definition_carries_the_headings_and_the_one_sequence_of_items(): void
    {
        $this->actingAs($this->user('u0004'));
        $ids = RecordFieldHeading::query()->whereRelation('recordModule', 'code', 'project')
            ->orderBy('sort_key')->pluck('id')->all();
        $field = fn (string $code): array => ['kind' => 'field', 'code' => $code];

        $this->get(self::DEFINITION)->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('headings', [
                ['id' => $ids[0], 'name' => '期間と予算', 'fieldCount' => 3],
                ['id' => $ids[1], 'name' => '説明', 'fieldCount' => 1],
            ])
            ->where('items', [
                $field('name'), $field('department'), $field('manager'), $field('status'),
                ['kind' => 'heading', 'id' => $ids[0]],
                $field('start_date'), $field('end_date'), $field('budget'),
                ['kind' => 'heading', 'id' => $ids[1]],
                $field('summary'), $field('milestones'),
            ])
        );
        $this->get('/records/admin/modules/project_issue')->assertInertia(fn (Assert $page) => $page
            ->where('headings', [])
            ->has('items', 8)
            ->where('items.0', $field('title'))
        );
        $this->get('/records/admin/modules/project_milestone')->assertInertia(fn (Assert $page) => $page
            ->where('headings', [])
            ->where('items.0', $field('name'))
        );
    }

    public function test_the_detail_the_create_form_and_the_edit_form_carry_the_heading_groups(): void
    {
        $this->actingAs($this->user('u0004'));
        $record = Record::query()->whereRelation('recordModule', 'code', 'project')->orderBy('id')->firstOrFail();
        $ids = RecordFieldHeading::query()->whereRelation('recordModule', 'code', 'project')
            ->orderBy('sort_key')->pluck('id')->all();
        $groups = [
            ['id' => null, 'name' => null, 'fieldCodes' => ['name', 'department', 'manager', 'status']],
            ['id' => $ids[0], 'name' => '期間と予算', 'fieldCodes' => ['start_date', 'end_date', 'budget']],
            ['id' => $ids[1], 'name' => '説明', 'fieldCodes' => ['summary']],
        ];

        $detail = "/records/data/project/{$record->id}";
        $urls = [$detail, '/records/data/project/create', "{$detail}/edit"];

        foreach ($urls as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('headings', $groups));
        }

        $this->get('/records/data/project_issue/create')->assertInertia(fn (Assert $page) => $page
            ->has('headings', 1)
            ->where('headings.0.id', null)
            ->where('headings.0.name', null)
            ->has('headings.0.fieldCodes', 8)
        );
    }

    public function test_the_module_update_route_carries_is_attachment_enabled(): void
    {
        $this->actingAs($this->user('u0004'));
        $milestone = '/records/admin/modules/project_milestone';

        $this->put(self::DEFINITION, ['is_attachment_enabled' => false])
            ->assertStatus(303)->assertRedirect(self::DEFINITION)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを更新しました']);
        $this->get(self::DEFINITION.'/edit')
            ->assertInertia(fn (Assert $page) => $page->where('module.isAttachmentEnabled', false));
        $this->from("{$milestone}/edit")->put($milestone, ['is_attachment_enabled' => true])
            ->assertRedirect("{$milestone}/edit")
            ->assertSessionHasErrors(['is_attachment_enabled' => '明細には設定できません']);
    }

    /**
     * 見出しの [id, 名前, 並び順](id の順)。
     *
     * @return list<mixed>
     */
    private function headingRows(): array
    {
        return array_values(RecordFieldHeading::query()->orderBy('id')->get(['id', 'name', 'sort_key'])->toArray());
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
