<?php

namespace App\Queries\Records;

use App\Enums\Menu\WorkMenuGroup;
use App\Models\Records\RecordModule;
use App\Support\DefinitionText;

/**
 * モジュール分類の画面の読み取り(docs の 設計/業務データの入れ物/モジュール分類/画面と検査.md 4.4、業務処理.md 3.9)。
 * 業務モジュールの無いアイテムも、並べ替えの順列がアイテムで決まるので行に残す(名前はコード)。表示中・非表示は {@see RecordModuleVisibility} で分ける。
 * 業務モジュールの判定をしない(管理のルートの認可だけ = 認可とメニュー/README.md 4 節)。
 *
 * @phpstan-type CategoryModule array{code: string, name: string}
 * @phpstan-type CategoryRow array{id: string, name: string, modules: list<CategoryModule>}
 * @phpstan-type UncategorizedModule array{code: string, name: string, isVisible: bool}
 */
class ModuleCategoryPageQuery
{
    public function __construct(
        private ModuleCategoryQuery $categories,
        private RecordModuleVisibility $visibility,
    ) {}

    /**
     * textMaxLengths = 分類の名前の欄の文字数の上限(管理の画面と同じ props)。
     *
     * @return array{categories: list<CategoryRow>, uncategorized: list<UncategorizedModule>, textMaxLengths: array{code: int, name: int, description: int}}
     */
    public function props(): array
    {
        /** @var array<string, string> $names */
        $names = RecordModule::query()->whereNull('parent_record_module_id')->orderBy('sort_key')->orderBy('code')->pluck('name', 'code')->all();
        $codesByCategory = $this->categories->moduleCodesByCategory();
        $directCodes = $this->categories->moduleCodesIn(WorkMenuGroup::GlobalNavigation, null);
        $moduleCodes = array_map('strval', array_keys($names));
        $visible = array_flip($this->visibility->visibleCodes($moduleCodes));
        $hidden = array_values(array_filter($moduleCodes, fn (string $code) => ! isset($visible[$code])));

        return [
            'categories' => array_map(fn (array $choice) => [
                ...$choice,
                'modules' => array_map(fn (string $code) => ['code' => $code, 'name' => $names[$code] ?? $code], $codesByCategory[$choice['id']] ?? []),
            ], $this->categories->choices()),
            'uncategorized' => [
                ...array_map(fn (string $code) => ['code' => $code, 'name' => $names[$code] ?? $code, 'isVisible' => true], $directCodes),
                ...array_map(fn (string $code) => ['code' => $code, 'name' => $names[$code], 'isVisible' => false], $hidden),
            ],
            'textMaxLengths' => DefinitionText::maxLengths(),
        ];
    }
}
