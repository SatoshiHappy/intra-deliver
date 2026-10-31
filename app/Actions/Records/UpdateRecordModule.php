<?php

namespace App\Actions\Records;

use App\Actions\Authz\RenameResourceGroup;
use App\Actions\Master\FillsAllLocales;
use App\Actions\Master\WritesCommonMaster;
use App\Actions\Menu\UpdateMenuDisplay;
use App\Enums\Menu\WorkMenuGroup;
use App\Enums\Records\RecordModuleAction;
use App\Enums\Records\RecordModuleVisibilityChange;
use App\Models\Records\RecordModule;
use App\Queries\Records\ModuleCategoryQuery;
use App\Queries\Records\RecordModuleVisibility;
use App\Queries\Records\RecordModuleVisibilityStopCatalog;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 業務モジュールを変える(明細のモジュールも)。規則の正本 = docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md
 * 11 節の「業務モジュールを変える」と「表示の止め」(入力の名前 = 設計/業務データの入れ物/画面/管理の画面.md 3.5、添付と参照制限の既定 =
 * 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md 2 節、分類 = 設計/業務データの入れ物/モジュール分類/業務処理.md 3.7)。
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
        private RecordModuleVisibilityStopCatalog $visibilityStops,
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
            $stopReason = $isDetails ? null : $this->visibilityStops->stopReason($module->id);
            [$columns, $isVisible, $categories] = $this->validated($module, $input, $wasVisible, $stopReason);
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
     * 手順 3: アイテムの配置を目標に揃え、作ったメニュー ID を返す(失敗のキーと文言 = 失敗と定義の変更.md 11 節の手順 3)。
     *
     * @param  list<string>|null  $target  null = どこにも置かない、[] = 直下、分類 ID の配列 = 分類ごと
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
     * 入力にあるキーを確かめ、[変える列 → 値, is_visible, categories] を返す。未指定は手順で今の値を使うため、変える列から外す
     * (未指定の読み方 = 失敗と定義の変更.md 11 節)。
     *
     * @param  array<array-key, mixed>  $input
     * @param  string|null  $stopReason  表示の止めの理由(止めが無い・明細のモジュールは null)
     * @return array{array<array-key, mixed>, bool|null, list<string>|null}
     *
     * @throws ValidationException 規則に反するとき
     */
    private function validated(RecordModule $module, array $input, bool $wasVisible, ?string $stopReason): array
    {
        $isDetails = $module->parent_record_module_id !== null;
        $icon = $input['icon'] ?? null;
        $isVisible = $input['is_visible'] ?? null;
        $categories = $input['categories'] ?? null;
        $attachment = $input['is_attachment_enabled'] ?? null;
        $restricted = $input['row_access_restricted_by_default'] ?? null;
        $this->throwIfAny([
            'name' => array_key_exists('name', $input) ? $this->textError($input['name'], '名前') : null,
            'description' => array_key_exists('description', $input) ? $this->descriptionError($input['description']) : null,
            'icon' => match (true) {
                $icon === null => null,
                $isDetails => '明細のモジュールにはアイコンを設定できません',
                default => $this->iconError($icon),
            },
            'is_visible' => $this->visibilityError($isVisible, $isDetails, $stopReason),
            'categories' => $this->categoriesError($categories, $isDetails, is_bool($isVisible) ? $isVisible : $wasVisible),
            'is_attachment_enabled' => match (true) {
                $attachment === null => null,
                $isDetails => '明細には設定できません',
                default => $this->flagError($attachment),
            },
            'row_access_restricted_by_default' => $this->restrictedByDefaultError($restricted, $isDetails),
        ]);

        return [
            array_intersect_key($input, array_flip(['name', 'description']))
                + ($icon === null ? [] : ['icon' => $icon])
                + ($attachment === null ? [] : ['is_attachment_enabled' => $attachment])
                + ($restricted === null ? [] : ['row_access_restricted_by_default' => $restricted]),
            is_bool($isVisible) ? $isVisible : null,
            is_array($categories) ? array_values(array_map(strval(...), $categories)) : null,
        ];
    }

    /**
     * is_visible の誤り(無ければ null。null は未指定)。表示の止めの行 = 失敗と定義の変更.md 11 節の「表示の止め」の表。
     */
    private function visibilityError(mixed $isVisible, bool $isDetails, ?string $stopReason): ?string
    {
        return match (true) {
            $isVisible === null => null,
            $isDetails => '明細のモジュールには表示の状態を設定できません',
            $isVisible === true && $stopReason !== null => $stopReason,
            default => $this->flagError($isVisible),
        };
    }

    /**
     * categories の誤り(無ければ null。null は未指定)。規則の正本 = 設計/業務データの入れ物/モジュール分類/業務処理.md 3.7。
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
     * 明細の行は親のレコードの参照制限に従うので、明細のモジュールには参照制限の既定を持たせない
     * (設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 2 節)。
     */
    private function restrictedByDefaultError(mixed $restricted, bool $isDetails): ?string
    {
        return match (true) {
            $restricted === null => null,
            $isDetails => '明細には設定できません',
            default => $this->flagError($restricted),
        };
    }
}
