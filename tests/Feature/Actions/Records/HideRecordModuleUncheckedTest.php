<?php

namespace Tests\Feature\Actions\Records;

use App\Actions\Records\Api\HideRecordModuleUnchecked;
use App\Models\Menu\Menu;
use App\Queries\Records\RecordModuleVisibility;
use Database\Seeders\WorkflowApplicationModules;
use LogicException;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールを判定せずに非表示にする公開の口(docs の 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表。ADR 0031 の決定 2):
 * 表示の業務モジュールの「業務」の 2 つのアイテム(global-nav-main・sitemap-main)を消し、既に非表示なら何も変えない。業務モジュールが無い・
 * 明細のモジュールは呼び手の誤り(LogicException)。メニューを消す業務処理の拒否は 422(キー is_visible)。初期データのプロジェクトを使う。
 */
final class HideRecordModuleUncheckedTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsRecordModuleMenus;

    public function test_hides_a_visible_module_and_leaves_a_hidden_one_as_it_is(): void
    {
        $this->assertCount(2, $this->items('project'));

        $this->hide('project');

        $this->assertSame([], $this->items('project'));
        $this->assertFalse(app(RecordModuleVisibility::class)->isVisible('project'));
        $before = $this->authorizationAndMenus();

        $this->hide('project');

        $this->assertSame($before, $this->authorizationAndMenus());
    }

    public function test_a_missing_or_details_module_is_an_error_of_the_caller(): void
    {
        $before = $this->authorizationAndMenus();

        foreach (['no_such_module', 'project_milestone', WorkflowApplicationModules::PLAN.'_milestones'] as $code) {
            $this->assertThrows(fn () => $this->hide($code), LogicException::class);
        }

        $this->assertSame($before, $this->authorizationAndMenus());
    }

    public function test_a_rejection_of_the_removal_has_the_key_is_visible(): void
    {
        $sitemapItem = Menu::query()->where('code', 'sitemap-main-records-project')->sole();
        Menu::factory()->item()->create(['code' => 'example-copy', 'original_menu_id' => $sitemapItem->id]);

        $errors = $this->validationErrors(fn () => $this->hide('project'));

        $this->assertSame(['is_visible' => 'コピーの元のメニューは削除できません: sitemap-main-records-project'], $errors);
        $this->assertCount(2, $this->items('project'));
    }

    private function hide(string $code): void
    {
        app(HideRecordModuleUnchecked::class)->execute($code);
    }
}
