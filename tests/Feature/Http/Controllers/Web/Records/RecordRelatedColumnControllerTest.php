<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Records\RecordRelatedColumn;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 管理の関連レコードの列 records.admin.related-columns.update と、定義の画面の props relatedColumns(docs の
 * 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 4〜7 節): ゲストとシステム管理者のロールを持たない人は 403、成功は定義の画面へ
 * 303 とトースト、404 は 422 より先、明細のモジュールはキー module、列の誤りはキー columns。初期データの「課題」(プロジェクトを参照する project)を使う。
 */
final class RecordRelatedColumnControllerTest extends TransactionalTestCase
{
    private const URL = '/records/admin/modules/project_issue/related-columns';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
    }

    #[TestWith([null])]
    #[TestWith(['u0001'])]
    public function test_the_guest_and_a_user_without_the_administrator_role_are_forbidden(?string $userCode): void
    {
        if ($userCode !== null) {
            $this->actingAs($this->user($userCode));
        }

        $this->put(self::URL, ['entry' => 'project', 'columns' => ['title']])->assertForbidden();

        $this->assertSame(0, RecordRelatedColumn::query()->count());
    }

    public function test_the_administrator_saves_the_columns_and_the_definition_shows_them(): void
    {
        $this->actingAs($this->user('u0004'));

        $this->put(self::URL, ['entry' => 'project', 'columns' => ['status', 'title']])
            ->assertStatus(303)->assertRedirect('/records/admin/modules/project_issue')
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '関連レコードの列を保存しました']);

        $this->get('/records/admin/modules/project_issue')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Admin/ModuleShow')
            ->has('relatedColumns', 1)
            ->where('relatedColumns.0.entry', 'project')
            ->where('relatedColumns.0.label', 'プロジェクト')
            ->where('relatedColumns.0.referencedModule', ['code' => 'project', 'name' => 'プロジェクト'])
            ->where('relatedColumns.0.columns', [
                ['code' => 'status', 'name' => '状態'],
                ['code' => 'title', 'name' => '課題名'],
            ])
            ->where('relatedColumns.0.candidates.0', ['code' => 'title', 'name' => '課題名'])
        );
    }

    public function test_a_module_without_record_references_and_a_details_module_have_no_rows(): void
    {
        $this->actingAs($this->user('u0004'));

        foreach (['project', 'project_milestone'] as $code) {
            $this->get("/records/admin/modules/{$code}")
                ->assertInertia(fn (Assert $page) => $page->where('relatedColumns', []));
        }
    }

    public function test_not_found_comes_before_the_input_errors_and_errors_return_with_their_keys(): void
    {
        $this->actingAs($this->user('u0004'));
        $definition = '/records/admin/modules/project_issue';

        $this->put('/records/admin/modules/nothing/related-columns', ['entry' => 1, 'columns' => 'x'])
            ->assertNotFound();
        $this->put(self::URL, ['entry' => 'title', 'columns' => 'x'])->assertNotFound();
        $this->from($definition)->put(self::URL, ['entry' => 'project', 'columns' => ['nothing']])
            ->assertRedirect($definition)
            ->assertSessionHasErrors(['columns' => '選択できない項目が含まれています']);
        $this->from('/records/admin/modules/project_milestone')
            ->put('/records/admin/modules/project_milestone/related-columns', ['entry' => 'project', 'columns' => []])
            ->assertSessionHasErrors(['module' => '明細には設定できません']);

        $this->assertSame(0, RecordRelatedColumn::query()->count());
    }

    private function user(string $code): User
    {
        return User::query()->where('code', $code)->sole();
    }
}
