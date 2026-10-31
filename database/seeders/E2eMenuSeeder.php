<?php

namespace Database\Seeders;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Menu\AddMenu;
use App\Actions\Menu\CreateMenuGroup;
use App\Actions\Menu\MenuFolderInput;
use App\Actions\Menu\MenuItemInput;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Menu\MenuGroupAction;
use App\Enums\Menu\MenuResourceKind;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Menu\MenuGroupCategory;
use Illuminate\Database\Seeder;

/**
 * E2E 専用のメニューの初期データ(docs の 設計/画面の意匠/検査と否決案.md 9 節)。入れ子のフォルダ・畳んだ帯の飛び出し・既定のアイコンを
 * 画面で確かめるための 1 組で、本番と開発の初期データには入れない(DatabaseSeeder からは呼ばない。scripts/ci/front が E2E の前に
 * php artisan db:seed --class=E2eMenuSeeder で流す)。MenuSeeder の後に 1 回入れる前提。すべて業務処理を通す。
 *
 * MenuSeeder と同じ形で、グローバルナビ(PC)とサイトマップ(PC)にグループを 1 つずつ作り(表示名はどちらも「E2E の入れ子」)、どちらにも
 * 同じ木を置く: 見出しのフォルダ「E2E 見出し」(中にフォルダ「E2E フォルダ」(中にアイテム「E2E 項目」と、子フォルダ「E2E 子フォルダ」
 * (中にアイテム「E2E 子項目」)))と、許可表に無いアイコン名のアイテム「E2E 不明アイコン」。メニュー ID は表全体で一意なので、グループ ID を
 * 接頭辞にする。グループの並びは 800 で、ランチャーのアプリの業務の前に出す(docs の 設計/メニュー/初期データ.md 4 節)。木の先頭のアイテムは
 * サイトマップ(E2E のアプリの先頭の画面)。アイテムの URL は MenuSeeder のルート(/・/menu/sitemap)だけを使い、ルートのリソースと execute の許可も
 * MenuSeeder のものを使う(重ねて入れると authz_policies の一意制約で落ちる)。足す許可は、ゲストユーザ・認証済みユーザへのグループの read だけ。
 */
class E2eMenuSeeder extends Seeder
{
    use FindsAuthzSubjects;

    /** グループ: カテゴリ => グループ ID */
    private const GROUPS = [
        'im_global_nav_pc' => 'e2e-global-nav',
        'im_sitemap_pc' => 'e2e-sitemap',
    ];

    /** グループの並び(ランチャーのアプリの業務 900 の前) */
    private const GROUP_SORT_NUMBER = 800;

    /** 許可表(resources/js/components/MenuIcon.tsx)に無いアイコン名 */
    private const UNKNOWN_ICON = 'e2e-no-such-icon';

    public function __construct(
        private CreateMenuGroup $createGroup,
        private AddMenu $addMenu,
        private CreatePolicy $createPolicy,
        private FindOrCreateSingleConditionSubjectGroup $findOrCreate,
    ) {}

    public function run(): void
    {
        $everyone = [$this->guestSubjectGroupId($this->findOrCreate), $this->authenticatedSubjectGroupId($this->findOrCreate)];

        foreach (self::GROUPS as $categoryCode => $groupCode) {
            $this->createTree($categoryCode, $groupCode);
            $groupResourceGroupId = AuthzResourceGroup::query()->where('code', MenuResourceKind::Group->resourceGroupCode($groupCode))->sole()->id;

            foreach ($everyone as $subjectGroupId) {
                $this->createPolicy->execute($groupResourceGroupId, $subjectGroupId, MenuGroupAction::RESOURCE_TYPE, MenuGroupAction::Read->value, PolicyEffect::Permit);
            }
        }
    }

    /**
     * グループと、その中の木(見出しのフォルダ・フォルダ・子フォルダ・アイテム)。
     */
    private function createTree(string $categoryCode, string $groupCode): void
    {
        $category = MenuGroupCategory::query()->where('code', $categoryCode)->sole();
        $group = $this->createGroup->execute($category->id, new MenuFolderInput($groupCode, ['ja' => 'E2E の入れ子'], sortNumber: self::GROUP_SORT_NUMBER));

        $headingInput = new MenuFolderInput($groupCode.'-heading', ['ja' => 'E2E 見出し'], icon32: 'folder');
        $heading = $this->addMenu->execute($group->menu_id, $headingInput);
        $folder = $this->addMenu->execute($heading->id, new MenuFolderInput($groupCode.'-folder', ['ja' => 'E2E フォルダ'], sortNumber: 0));
        $this->addMenu->execute($folder->id, new MenuItemInput($groupCode.'-item', '/menu/sitemap', ['ja' => 'E2E 項目'], sortNumber: 0, icon32: 'map'));
        $childFolder = $this->addMenu->execute($folder->id, new MenuFolderInput($groupCode.'-child-folder', ['ja' => 'E2E 子フォルダ'], sortNumber: 1));
        $this->addMenu->execute($childFolder->id, new MenuItemInput($groupCode.'-child-item', '/', ['ja' => 'E2E 子項目'], icon32: 'house'));
        $this->addMenu->execute($group->menu_id, new MenuItemInput($groupCode.'-unknown-icon', '/', ['ja' => 'E2E 不明アイコン'], sortNumber: 1, icon32: self::UNKNOWN_ICON));
    }
}
