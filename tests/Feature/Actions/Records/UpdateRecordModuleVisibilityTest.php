<?php

namespace Tests\Feature\Actions\Records;

use App\Enums\Records\RecordModuleVisibilityChange;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResource;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Menu\Menu;
use App\Models\Menu\MenuTranslation;
use App\Models\Records\Record;
use App\Queries\Records\ModuleCategoryPageQuery;
use App\Queries\Records\RecordModuleVisibility;
use App\Queries\Records\RecordModuleVisibilityStopCatalog;
use Database\Seeders\WorkflowApplicationModules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CatchesValidationErrors;
use Tests\Support\ReadsRecordModuleMenus;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールを変える業務処理の表示の切替(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節の手順 3 と戻り値、
 * 認可とメニュー/メニューと初期データ.md 5.1・8 節の「業務モジュールを変える業務処理」の非表示の分): is_visible の偽は「業務」の 2 つのアイテム(と表示名)を消し、
 * リソース・ポリシー・レコードは残る。真は無いアイテムを今の名前とアイコンで末尾に作る。変える前の状態とは比べないので、片方だけの状態からも揃う。
 * 戻り値は表示の変化(グローバルナビのアイテムの有無の前後の比較)。アイテムを作る・消す段の失敗はキー is_visible で、全体が巻き戻る。
 * 初期データのプロジェクト(並び 100)・課題(並び 110)と組み込みの会議(並び 120)を使う。
 */
final class UpdateRecordModuleVisibilityTest extends TransactionalTestCase
{
    use CatchesValidationErrors;
    use ReadsRecordModuleMenus;

    public function test_hiding_removes_both_items_and_their_names_and_keeps_the_resource_the_policies_and_the_records(): void
    {
        $itemIds = Menu::query()->where('code', 'like', '%-records-project')->pluck('id')->all();
        $records = Record::query()->count();
        $resourceGroup = AuthzResourceGroup::query()->where('code', 'record-module-project')->sole();

        $this->assertSame(RecordModuleVisibilityChange::Hidden, $this->update('project', ['is_visible' => false]));

        $this->assertSame([], $this->items('project'));
        $this->assertSame(0, MenuTranslation::query()->whereIn('menu_id', $itemIds)->count());
        $this->assertTrue(AuthzResource::query()->where('authz_resource_group_id', $resourceGroup->id)->exists());
        $this->assertSame(8, AuthzPolicy::query()->where('authz_resource_group_id', $resourceGroup->id)->count());
        $this->assertSame($records, Record::query()->count());
        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update('project', ['is_visible' => false]));
    }

    public function test_renaming_while_hidden_creates_no_item_and_showing_creates_both_at_the_end_with_the_current_name_and_icon(): void
    {
        $this->update('project', ['is_visible' => false]);

        $this->update('project', ['name' => '案件', 'icon' => 'truck']);
        $this->assertSame([], $this->items('project'));

        $this->assertSame(RecordModuleVisibilityChange::Shown, $this->update('project', ['is_visible' => true]));

        $this->assertSame([
            'global-nav-main-records-project' => [['en' => '案件', 'ja' => '案件'], 'truck'],
            'sitemap-main-records-project' => [['en' => '案件', 'ja' => '案件'], 'truck'],
        ], $this->items('project'));
        // 初期データのアイテムの並びはプロジェクト 100・課題 110・会議 120(申請書の業務モジュール 2 つはコンテンツを作る業務処理が消す = ADR 0031)。
        // 戻したプロジェクトは残ったアイテムの後(最大 + 10)
        $sortNumbers = array_map(fn (array $row) => $row['sort_number'], $this->itemRows('project'));
        $this->assertSame([
            'global-nav-main-records-project' => 130, 'sitemap-main-records-project' => 130,
        ], $sortNumbers);
    }

    public function test_showing_and_renaming_in_one_update_creates_the_items_with_the_new_name(): void
    {
        $this->update('project', ['is_visible' => false]);

        $this->assertSame(RecordModuleVisibilityChange::Shown, $this->update('project', ['name' => '案件', 'icon' => 'truck', 'is_visible' => true]));

        $this->assertSame([['en' => '案件', 'ja' => '案件'], 'truck'], $this->items('project')['sitemap-main-records-project']);
        $this->assertSame(['en' => '案件', 'ja' => '案件'], $this->resourceGroupNames('project'));
    }

    /**
     * 申請書の業務モジュールのグループ「ワークフロー」のアイテム(MenuSeeder が入れる)は、業務モジュールを非表示にしても残り、表示に戻しても増えない。
     * 表示中の判定(業務モジュールの更新の戻り値・モジュール分類の画面)はグループ「業務」のアイテムだけで決まる
     * (docs の 設計/ワークフロー/申請の周辺/メニューと導線.md の 1.1 節)。
     */
    public function test_the_items_in_the_group_workflow_stay_when_hiding_and_are_not_added_when_showing(): void
    {
        // 申請書の業務モジュールは初期データで非表示で、表示の止めがある(ADR 0031)。表示にする向きも見るため止めの実装を外す
        $this->app->instance(RecordModuleVisibilityStopCatalog::class, new RecordModuleVisibilityStopCatalog([]));
        $code = WorkflowApplicationModules::PLAN;
        $workflowItems = [
            'global-nav-workflow-records-data-index-project-plan-application',
            'sitemap-workflow-records-data-index-project-plan-application',
        ];
        $ids = fn () => Menu::query()->whereIn('code', $workflowItems)->orderBy('code')->pluck('id', 'code')->all();
        $urlCount = fn () => Menu::query()->where('url', "/records/data/{$code}")->count();
        $before = $ids();
        $this->assertCount(2, $before);
        $this->assertSame([[], 2], [$this->items($code), $urlCount()]);

        $this->assertSame(RecordModuleVisibilityChange::Shown, $this->update($code, ['is_visible' => true]));

        $groupItems = ["global-nav-main-records-{$code}", "sitemap-main-records-{$code}"];
        $this->assertSame($groupItems, array_keys($this->items($code)));
        $this->assertSame([$before, 4], [$ids(), $urlCount()]);
        $this->assertTrue(app(RecordModuleVisibility::class)->isVisible($code));

        $this->assertSame(RecordModuleVisibilityChange::Hidden, $this->update($code, ['is_visible' => false]));

        $this->assertSame([], $this->items($code));
        $this->assertSame([$before, 2], [$ids(), $urlCount()]);
        $this->assertFalse(app(RecordModuleVisibility::class)->isVisible($code));
        $uncategorized = app(ModuleCategoryPageQuery::class)->props()['uncategorized'];
        $hidden = array_filter($uncategorized, fn (array $module) => ! $module['isVisible']);
        $this->assertContains($code, array_column($hidden, 'code'));
    }

    public function test_the_same_visibility_on_both_items_keeps_their_ids_and_order(): void
    {
        $before = $this->itemRows('project');

        $this->assertSame(RecordModuleVisibilityChange::Unchanged, $this->update('project', ['is_visible' => true]));

        $this->assertSame($before, $this->itemRows('project'));
    }

    /**
     * 片方のアイテムだけがある状態から、真で揃い、偽で消える(変える前の状態とは比べない)。表示の変化はグローバルナビのアイテムの有無で決まる。
     *
     * @return array<string, array{string, bool, list<string>, RecordModuleVisibilityChange}>
     */
    public static function oneSidedStates(): array
    {
        return [
            'サイトマップだけ無い → 真' => ['sitemap-main-records-project', true, ['global-nav-main-records-project', 'sitemap-main-records-project'], RecordModuleVisibilityChange::Unchanged],
            'グローバルナビだけ無い → 真' => ['global-nav-main-records-project', true, ['global-nav-main-records-project', 'sitemap-main-records-project'], RecordModuleVisibilityChange::Shown],
            'サイトマップだけ無い → 偽' => ['sitemap-main-records-project', false, [], RecordModuleVisibilityChange::Hidden],
            'グローバルナビだけ無い → 偽' => ['global-nav-main-records-project', false, [], RecordModuleVisibilityChange::Unchanged],
        ];
    }

    /**
     * @param  list<string>  $expectedItems
     */
    #[DataProvider('oneSidedStates')]
    public function test_a_one_sided_state_is_aligned_in_one_update(string $missingItem, bool $isVisible, array $expectedItems, RecordModuleVisibilityChange $expectedChange): void
    {
        Menu::query()->where('code', $missingItem)->delete();
        $remaining = Menu::query()->where('code', 'like', '%-records-project')->pluck('id', 'code')->all();

        $this->assertSame($expectedChange, $this->update('project', ['is_visible' => $isVisible]));

        $this->assertSame($expectedItems, array_keys($this->items('project')));
        // 真では残っていたアイテムをそのまま使う(作り直さない)
        $this->assertSame($isVisible ? $remaining : [], array_intersect_key(Menu::query()->where('code', 'like', '%-records-project')->pluck('id', 'code')->all(), $remaining));
    }

    public function test_a_rejection_of_the_removal_rolls_back_everything_with_the_key_is_visible(): void
    {
        $sitemapItem = Menu::query()->where('code', 'sitemap-main-records-project')->sole();
        Menu::factory()->item()->create(['code' => 'example-copy', 'original_menu_id' => $sitemapItem->id]);
        $before = [$this->moduleValues('project'), $this->authorizationAndMenus()];

        $errors = $this->validationErrors(fn () => $this->update('project', ['name' => '案件', 'is_visible' => false]));

        $this->assertSame(['is_visible' => 'コピーの元のメニューは削除できません: sitemap-main-records-project'], $errors);
        $this->assertSame($before, [$this->moduleValues('project'), $this->authorizationAndMenus()]);
    }

    public function test_a_missing_work_group_rejects_showing_with_the_key_is_visible_and_leaves_nothing(): void
    {
        $this->update('project', ['is_visible' => false]);
        // サイトマップの「業務」のグループだけが無い形(ルートのメニュー ID を変える = グループを引けない)
        Menu::query()->where('code', 'sitemap-main')->update(['code' => 'example-renamed-group']);
        $before = [$this->moduleValues('project'), $this->authorizationAndMenus()];

        $errors = $this->validationErrors(fn () => $this->update('project', ['name' => '案件', 'is_visible' => true]));

        $this->assertSame(['is_visible' => 'メニューのグループ「業務」がありません'], $errors);
        $this->assertSame($before, [$this->moduleValues('project'), $this->authorizationAndMenus()]);
        $this->assertSame([], $this->items('project'));
    }
}
