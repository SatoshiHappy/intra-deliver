<?php

namespace Tests\Feature\Http\Controllers\Web\Records;

use App\Models\Master\User;
use App\Models\Menu\Menu;
use App\Models\Records\RecordModule;
use App\Queries\Workflow\WorkflowRecordModuleVisibilityStops;
use Carbon\CarbonImmutable;
use Database\Seeders\WorkflowApplicationModules;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールの編集の画面の表示の止め(docs の 設計/業務データの入れ物/画面/管理の画面.md 3.5。ADR 0031 の決定 5): props の
 * visibilityStopReason は、申請書の業務モジュール(コンテンツが指す)で止めの理由の文言、ほかの業務モジュールと明細のモジュールで null。
 * 画面が送る形(is_visible = 偽・categories = 空)は非表示のまま通り、真は元の画面へ戻りキー is_visible に止めの理由が付く。
 * チェックの描き方(無効・外れた形・理由を読み上げの説明に結ぶ)は E2E の records-admin-visibility-stop の spec が確かめる。
 * 初期データを入れ、システム管理者で開く。
 */
final class RecordModuleEditVisibilityStopTest extends TransactionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-11 03:00:00 UTC');
        $this->withoutVite();
        $this->actingAs(User::query()->where('code', 'u0004')->sole());
    }

    #[TestWith([WorkflowApplicationModules::PLAN, WorkflowRecordModuleVisibilityStops::REASON])]
    #[TestWith([WorkflowApplicationModules::ISSUE, WorkflowRecordModuleVisibilityStops::REASON])]
    #[TestWith(['project', null])]
    #[TestWith([WorkflowApplicationModules::PLAN.'_milestones', null])]
    public function test_the_edit_form_carries_the_reason_of_the_stop(string $code, ?string $reason): void
    {
        $this->get("/records/admin/modules/{$code}/edit")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Records/Admin/ModuleEdit')
            ->where('visibilityStopReason', $reason)
        );
    }

    public function test_the_form_of_a_stopped_module_saves_while_hidden_and_showing_returns_the_reason(): void
    {
        $code = WorkflowApplicationModules::PLAN;
        $url = "/records/admin/modules/{$code}";

        $this->put($url, ['name' => '計画の申請', 'is_visible' => false, 'categories' => []])
            ->assertStatus(303)->assertRedirect($url)
            ->assertInertiaFlash('toast', ['type' => 'success', 'message' => '業務モジュールを更新しました']);
        $this->assertSame('計画の申請', RecordModule::query()->where('code', $code)->sole()->name);

        $this->from("{$url}/edit")->put($url, ['name' => '申請', 'is_visible' => true])
            ->assertRedirect("{$url}/edit")
            ->assertSessionHasErrors(['is_visible' => WorkflowRecordModuleVisibilityStops::REASON]);
        $this->assertSame('計画の申請', RecordModule::query()->where('code', $code)->sole()->name);
        $this->assertSame(0, Menu::query()->where('code', 'like', "%-main-records-{$code}")->count());
    }
}
