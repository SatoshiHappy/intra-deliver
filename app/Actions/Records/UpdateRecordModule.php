<?php

namespace App\Actions\Records;

use App\Actions\Authz\RenameResourceGroup;
use App\Actions\Master\FillsAllLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Actions\Menu\UpdateMenuDisplay;
use App\Enums\Menu\MenuIcon;
use App\Enums\Menu\WorkMenuGroup;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordModuleVisibilityChange;
use App\Enums\Records\RowAccessScope;
use App\Models\Records\RecordModule;
use App\Queries\Records\ModuleCategoryQuery;
use App\Queries\Records\RecordModuleVisibility;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 業務モジュールを変える(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節の「業務モジュールを変える」、認可とメニュー/README.md 1 節、メニューと初期データ.md 5.1、
 * モジュール分類/業務処理.md 3.7)。明細のモジュールも変えられる。
 *
 * 入力 = { name, description, icon, is_visible, categories, row_access_scope }(画面/管理の画面.md 3.5、
 * 見える範囲 = レコードの共通機能/業務モジュールの管理.md 2 節。キーはエラーのキー)。入力に無いキーは今の値のまま(項目を変えると同じ規則)。
 * 値のキー name・description の null は空(名前は 422、説明は消す)、既定を持つキー icon・is_visible・categories・row_access_scope の null は未指定
 * (今の値のまま)。明細のモジュールで icon・is_visible・categories・row_access_scope が null 以外なら 422(キーはその名前。明細のモジュールは
 * アイコンも表示の状態も分類も見える範囲も持たない)。見える範囲は all・related のどちらか(値を書くだけ。レコードは変えない)。検証は作成と同じ文言
 * (名前の空・長さ・U+0000、説明の長さ、アイコンが {@see MenuIcon::recordModuleChoices()} にあること)と、is_visible が真偽であること、categories が
 * 重ならない分類 ID の配列で、どれもロックの後に引き直した分類にあり、更新の後に非表示(is_visible が偽、または未指定で今が非表示)なら空であること。
 *
 * 共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で、変える前の表示中の判定({@see RecordModuleVisibility})を検証と手順 1 の前に読み、
 * 同じトランザクションで:
 * - 手順 1: record_modules の名前・説明・アイコン・見える範囲
 * - 手順 2: 名前が変われば、認可のリソースグループ record-module-<コード> の表示名(すべてのロケール)を新しい名前に({@see RenameResourceGroup})
 * - 手順 3: is_visible か categories が入力にあれば、「業務」の 2 つのグループ({@see WorkMenuGroup})のアイテムの配置を揃える({@see SyncRecordModuleItems})。
 *   目標 = 更新の後に非表示ならどこにも置かない、表示中なら categories(未指定なら今の所属)の分類ごと(空なら直下)。作るアイテムは 1 で書いた後の名前とアイコン。
 *   変える前の状態とは比べない(片方だけがある状態からも、どちらの向きでも 1 回で揃う)。失敗は原因によらずキー is_visible(文言は下位の業務処理のもの)
 * - 手順 4: 名前かアイコンが変われば、3 で作ったものを除いたすべてのアイテム(直下とすべての分類の中)の表示名(すべてのロケール)と icon_32 を、
 *   書いた後の名前とアイコンに({@see UpdateMenuDisplay})
 * 手順 2〜4 は親の無い業務モジュールだけ(明細のモジュールはリソースもアイテムも持たない)。下位の業務処理が拒めば全体が巻き戻り、
 * 手順 2・4 の誤りは下位のキーのまま(resource_group・menu・locale)で返る(規則と業務処理/失敗と定義の変更.md 10 節。画面は 設計/業務データの入れ物/画面/管理の画面.md
 * 3 節の冒頭の規則で出す)。戻り値 = 表示の変化(変える前と後の表示中の判定を比べたもの。明細のモジュールはいつも変えていない)。入口はこれでトーストの文言を選ぶ。
 * コード・親・認可のリソースとポリシー・レコード・項目・規則は変えない。業務モジュールの判定はしない(管理のルートの認可だけ = 認可とメニュー/README.md 4 節)。
 */
class UpdateRecordModule
{
    use FillsAllLocales;
    use LocksRecordModule;
    use NormalizesInput;
    use ValidatesDefinitionText;
    use WritesCommonMaster;

    public function __construct(
        private RecordModuleVisibility $visibility,
        private ModuleCategoryQuery $categories,
        private RenameResourceGroup $renameResourceGroup,
        private SyncRecordModuleItems $syncItems,
        private UpdateMenuDisplay $updateMenuDisplay,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $moduleCode, array $input): RecordModuleVisibilityChange
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($moduleCode, $input) {
            $module = $this->lockRecordModuleExclusively($moduleCode);
            $isDetails = $module->parent_record_module_id !== null;
            $wasVisible = ! $isDetails && $this->visibility->isVisible($module->code);
            [$columns, $isVisible, $categories] = $this->validated($module, $input, $wasVisible);
            $module->update($columns);

            if ($isDetails) {
                return RecordModuleVisibilityChange::Unchanged;
            }

            $isVisibleAfter = $isVisible ?? $wasVisible;
            $this->followAuthorizationAndMenus($module, $isVisible !== null || $categories !== null, $isVisibleAfter, $categories);

            return RecordModuleVisibilityChange::between($wasVisible, $isVisibleAfter);
        });
    }

    /**
     * 親の無い業務モジュールの手順 2〜4。ロケールの一覧は名前を渡すときだけ引く。
     *
     * @param  bool  $placesItems  手順 3 を行うか(is_visible か categories が入力にある)
     * @param  list<string>|null  $categories  選んだ分類 ID(null = 未指定 = 今の所属)
     *
     * @throws ValidationException 下位の業務処理が拒むとき
     */
    private function followAuthorizationAndMenus(RecordModule $module, bool $placesItems, bool $isVisibleAfter, ?array $categories): void
    {
        $isNameOrIconChanged = $module->wasChanged(['name', 'icon']);
        $names = $isNameOrIconChanged || ($placesItems && $isVisibleAfter)
            ? $this->forAllLocales($module->name)
            : [];

        if ($module->wasChanged('name')) {
            $this->renameResourceGroup->execute(RecordModuleAction::resourceGroupCode($module->code), $names);
        }

        $createdItems = $placesItems
            ? $this->placeItems($module, $isVisibleAfter ? ($categories ?? $this->categories->categoryIdsOf($module->code)) : null, $names)
            : [];

        if ($isNameOrIconChanged) {
            $this->updateMenuDisplay->execute(array_values(array_diff($this->categories->itemCodes($module->code), $createdItems)), $names, $module->icon);
        }
    }

    /**
     * 手順 3: アイテムの配置を目標(null = どこにも置かない、[] = 直下、分類 ID の配列 = 分類ごと)に揃え、作ったメニュー ID を返す。失敗は原因によらずキー
     * is_visible で、文言は下位の業務処理(「業務」のグループが無い = 作成と同じ文言、メニューを足す・消す業務処理の拒否)のもの。
     *
     * @param  list<string>|null  $target
     * @param  array<string, string>  $names  ロケールのコード → 表示名
     * @return list<string>
     *
     * @throws ValidationException 下位の業務処理が拒むとき(キー is_visible)
     */
    private function placeItems(RecordModule $module, ?array $target, array $names): array
    {
        try {
            return $this->syncItems->execute($module, $target, $names)['created'];
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['is_visible' => array_merge(...array_values($exception->errors()))]);
        }
    }

    /**
     * 入力にあるキーを確かめ、[変える列 → 値(icon・row_access_scope の null は未指定なので含めない), is_visible(null = 未指定),
     * categories(null = 未指定)] を返す。
     *
     * @param  array<array-key, mixed>  $input
     * @return array{array<array-key, mixed>, bool|null, list<string>|null}
     *
     * @throws ValidationException 規則に反するとき
     */
    private function validated(RecordModule $module, array $input, bool $wasVisible): array
    {
        $isDetails = $module->parent_record_module_id !== null;
        $icon = $input['icon'] ?? null;
        $isVisible = $input['is_visible'] ?? null;
        $categories = $input['categories'] ?? null;
        $scope = $input['row_access_scope'] ?? null;
        $this->throwIfAny([
            'name' => array_key_exists('name', $input) ? $this->textError($input['name'], '名前') : null,
            'description' => array_key_exists('description', $input) ? $this->descriptionError($input['description']) : null,
            'icon' => match (true) {
                $icon === null => null,
                $isDetails => '明細のモジュールにはアイコンを設定できません',
                default => $this->iconError($icon),
            },
            'is_visible' => match (true) {
                $isVisible === null => null,
                $isDetails => '明細のモジュールには表示の状態を設定できません',
                default => $this->flagError($isVisible),
            },
            'categories' => $this->categoriesError($categories, $isDetails, is_bool($isVisible) ? $isVisible : $wasVisible),
            'row_access_scope' => $this->scopeError($scope, $isDetails),
        ]);

        return [
            array_intersect_key($input, array_flip(['name', 'description']))
                + ($icon === null ? [] : ['icon' => $icon])
                + ($scope === null ? [] : ['row_access_scope' => $scope]),
            is_bool($isVisible) ? $isVisible : null,
            is_array($categories) ? array_values(array_map(strval(...), $categories)) : null,
        ];
    }

    /**
     * categories の誤り(無ければ null。null は未指定): 明細のモジュールでないこと、分類 ID の配列であること、重ならないこと、どれもロックの後に
     * 引き直した分類(global-nav-main の分類のフォルダ)にあること、更新の後に非表示なら空であること(モジュール分類/業務処理.md 3.7)。
     */
    private function categoriesError(mixed $categories, bool $isDetails, bool $isVisibleAfter): ?string
    {
        return match (true) {
            $categories === null => null,
            $isDetails => '明細のモジュールにはモジュール分類を設定できません',
            ! is_array($categories) || ! array_is_list($categories) || array_filter($categories, fn (mixed $id) => ! is_string($id)) !== [] => 'モジュール分類は分類 ID の配列で指定してください',
            count(array_unique($categories)) !== count($categories) => '同じモジュール分類が重複しています',
            array_diff($categories, array_keys($this->categories->folders(WorkMenuGroup::GlobalNavigation))) !== [] => 'モジュール分類が見つかりません。画面を再読み込みしてから再度操作してください',
            ! $isVisibleAfter && $categories !== [] => 'サイドバーに表示しない業務モジュールにはモジュール分類を設定できません',
            default => null,
        };
    }

    /**
     * row_access_scope の誤り(無ければ null。null は未指定): 明細のモジュールでないこと(明細の行は親のレコードの判定に従う)、all・related のどちらかであること
     * (レコードの共通機能/業務モジュールの管理.md 2 節)。
     */
    private function scopeError(mixed $scope, bool $isDetails): ?string
    {
        return match (true) {
            $scope === null => null,
            $isDetails => '明細には設定できません',
            ! is_string($scope) || RowAccessScope::tryFrom($scope) === null => '見える範囲は全員か関係する人だけを選択してください',
            default => null,
        };
    }
}
