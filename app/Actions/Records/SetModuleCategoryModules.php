<?php

namespace App\Actions\Records;

use App\Actions\Master\FillsAllLocales;
use App\Actions\Menu\RemoveMenu;
use App\Enums\Menu\WorkMenuGroup;
use App\Models\Menu\Menu;
use App\Models\Records\RecordModule;
use App\Queries\Records\ModuleCategoryQuery;
use App\Queries\Records\RecordModuleVisibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 分類の中の業務モジュールを揃える(docs の 設計/業務データの入れ物/モジュール分類/業務処理.md 3.10。分類の画面の「業務モジュールの追加」の Dialog)。
 * 入力 = 分類 ID と codes(その分類に置く業務モジュールのコードの集合。順は新しく入れるものの並びにだけ効く)。共通の段({@see ModuleCategoryFolders::write()}:
 * 分類が無ければ 404。サイトマップ側のフォルダが無ければ作り直す)の後で codes を確かめ(キー codes の 422: 要素が文字列でない・重なり・無いコード・
 * 明細のモジュール・非表示の業務モジュール)、{@see self::placeMembers()} で揃える。下位の業務処理の拒否はキー category(文言は下位のもの)で、全体が巻き戻る。
 * 今と同じ集合なら何も変えずに成功する。返り値なし。
 */
class SetModuleCategoryModules
{
    use FillsAllLocales;

    public function __construct(
        private ModuleCategoryFolders $folders,
        private ModuleCategoryQuery $categories,
        private RecordModuleVisibility $visibility,
        private SyncRecordModuleItems $syncItems,
        private RemoveMenu $removeMenu,
    ) {}

    /**
     * @param  array<array-key, mixed>  $codes
     *
     * @throws ModelNotFoundException<Menu> 分類が無いとき
     * @throws ValidationException codes が規則に反するとき(キー codes)、下位の業務処理が拒むとき(キー category)
     */
    public function execute(string $categoryId, array $codes): void
    {
        $this->folders->write($categoryId, true, function () use ($categoryId, $codes) {
            $validated = $this->validated($codes);

            try {
                $this->placeMembers($categoryId, $validated);
            } catch (ValidationException $exception) {
                throw ValidationException::withMessages(['category' => array_merge(...array_values($exception->errors()))]);
            }
        });
    }

    /**
     * 手順 1・2(共通マスタのロックの中で呼ぶ。分類を消す業務処理 = 3.4 の手順 1・2 は codes = [] で呼ぶ): 対象 = 今の中の業務モジュール(2 つのフォルダの
     * 中のアイテムから読んだコード。global-nav-main の中の並び、次にサイトマップだけにあるものをコードの順)と、続けて codes のうちそこに無いもの(codes の順)。
     * 業務モジュールごとに配置を揃える({@see SyncRecordModuleItems})。目標 = 表示中なら「今の所属 − この分類」に、codes にあれば「+ この分類」(空なら直下 =
     * 末尾に作られる)、表示中でなければどこにも置かない。record_modules に無いコード(親の無い業務モジュールでないもの)のこの分類のアイテムは消すだけ。
     * 表示中と所属は始める前にまとめて読む(1 つの業務モジュールを揃えても、ほかの業務モジュールの表示中と所属は変わらない)。
     *
     * @param  list<string>  $codes  確かめ済みのコード(表示中の親の無い業務モジュール)
     *
     * @throws ValidationException 下位の業務処理が拒むとき(キーは下位のもの)
     */
    public function placeMembers(string $categoryId, array $codes): void
    {
        $globalCodes = $this->categories->moduleCodesIn(WorkMenuGroup::GlobalNavigation, $categoryId);
        $sitemapOnlyCodes = array_diff($this->categories->moduleCodesIn(WorkMenuGroup::Sitemap, $categoryId), $globalCodes);
        sort($sitemapOnlyCodes, SORT_STRING);
        $currentCodes = [...$globalCodes, ...$sitemapOnlyCodes];
        $moduleCodes = [...$currentCodes, ...array_values(array_diff($codes, $currentCodes))];
        $modules = RecordModule::query()->whereNull('parent_record_module_id')->whereIn('code', $moduleCodes)->get()->keyBy('code');
        $visibleCodes = array_flip($this->visibility->visibleCodes($moduleCodes));
        $memberships = $this->categories->memberships($moduleCodes);
        $kept = array_flip($codes);

        foreach ($moduleCodes as $moduleCode) {
            $module = $modules->get($moduleCode);

            if ($module === null) {
                $this->removeMenu->execute(array_map(fn (WorkMenuGroup $group) => $group->categoryItemCode($categoryId, $moduleCode), WorkMenuGroup::cases()));

                continue;
            }

            $target = isset($visibleCodes[$moduleCode])
                ? [...array_values(array_diff($memberships[$moduleCode] ?? [], [$categoryId])), ...(isset($kept[$moduleCode]) ? [$categoryId] : [])]
                : null;
            $this->syncItems->execute($module, $target, $this->forAllLocales($module->name));
        }
    }

    /**
     * codes を確かめ、コードの list を返す(業務処理.md 3.10 の拒否。最初に当たった 1 つの文言をキー codes で)。
     *
     * @param  array<array-key, mixed>  $codes
     * @return list<string>
     *
     * @throws ValidationException 規則に反するとき(キー codes)
     */
    private function validated(array $codes): array
    {
        $strings = array_values(array_filter($codes, is_string(...)));
        $modules = RecordModule::query()->whereIn('code', $strings)->get()->keyBy('code');
        $parentless = array_values(array_filter($strings, fn (string $code) => $modules->get($code)?->parent_record_module_id === null));
        $visibleCodes = $this->visibility->visibleCodes($parentless);
        $error = match (true) {
            ! array_is_list($codes) || count($strings) !== count($codes) => '業務モジュールの一覧の形が正しくありません',
            count(array_unique($strings)) !== count($strings) => '同じ業務モジュールが重複しています',
            array_diff($strings, $modules->keys()->all()) !== [] => '業務モジュールが見つかりません。画面を再読み込みしてから再度操作してください',
            count($parentless) !== count($strings) => '明細のモジュールにはモジュール分類を設定できません',
            count($visibleCodes) !== count($strings) => 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません',
            default => null,
        };

        if ($error !== null) {
            throw ValidationException::withMessages(['codes' => $error]);
        }

        return $strings;
    }
}
