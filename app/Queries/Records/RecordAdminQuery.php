<?php

namespace App\Queries\Records;

use App\Enums\Menu\MenuIcon;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Models\Records\RecordRule;
use App\Models\Records\RelativeDefault;
use App\Support\DefinitionText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * 管理の画面の読み取り(docs の 設計/業務データの入れ物/画面/管理の画面.md 3 節): 業務モジュールの一覧・作成・定義・編集と、項目の追加と編集の props。
 * 業務モジュールの判定をせず、明細を含むすべての業務モジュールを出す(認可とメニュー/README.md 4 節。入口はルートの認可だけで絞る)。
 * 並び: 業務モジュールは {@see ordered()}、項目・選択肢・規則は {@see RecordDefinitionQuery}。
 * isVisible = サイドバーに出ているか({@see RecordModuleVisibility}。明細のモジュールは null = 認可とメニュー/メニューと初期データ.md 5.1)。
 * isAttachmentEnabled = 添付ファイルの追加・削除を許可するか・rowAccessRestrictedByDefault = 参照制限の既定
 * (どちらも明細のモジュールは null = レコードの共通機能/業務モジュールの管理.md 2 節)。
 * headings・items = 見出しと項目の 1 本の並び(同 3 節・6 節。{@see RecordHeadingGroups})。
 * 編集の画面のモジュール分類(所属の分類 ID と、すべての分類)は {@see ModuleCategoryQuery}(モジュール分類/画面と検査.md 4.2)。
 * 編集の画面の visibilityStopReason = 表示の止めの理由({@see RecordModuleVisibilityStopCatalog}。止めが無い・明細のモジュールは null =
 * 画面/管理の画面.md 3.5)。
 *
 * @phpstan-type ModuleRow array{code: string, name: string, parentCode: string|null, isVisible: bool|null}
 * @phpstan-type ModuleSummary array{code: string, name: string}
 * @phpstan-type IconChoice array{value: string, label: string}
 * @phpstan-type TextMaxLengths array{code: int, name: int, description: int}
 * @phpstan-type StringMaxLengths array{singleLine: int, multiline: int, multilineMax: int}
 *
 * @phpstan-import-type CategoryChoice from ModuleCategoryQuery
 */
class RecordAdminQuery
{
    public function __construct(
        private RecordDefinitionQuery $definitions,
        private RecordModuleVisibility $visibility,
        private ModuleCategoryQuery $categories,
        private RecordHeadingGroups $headingGroups,
        private RecordModuleVisibilityStopCatalog $visibilityStops,
    ) {}

    /**
     * 業務モジュールの一覧(画面/管理の画面.md 3.1)。表示中の判定は親の無いものをまとめて 1 回で引く。
     *
     * @return list<ModuleRow>
     */
    public function modules(): array
    {
        $modules = $this->ordered(RecordModule::query());
        $codes = [];

        foreach ($modules as $module) {
            $codes[$module->id] = $module->code;
        }

        $visibleCodes = $this->visibility->visibleCodes(array_values(array_map(
            fn (RecordModule $module) => $module->code,
            array_filter($modules, fn (RecordModule $module) => $module->parent_record_module_id === null),
        )));

        return array_map(
            fn (RecordModule $module) => $this->row($module, $module->parent_record_module_id === null ? null : $codes[$module->parent_record_module_id], $visibleCodes),
            $modules,
        );
    }

    /**
     * 業務モジュールの定義の画面の props(画面/管理の画面.md 3.3)。見出しの切替の業務モジュールは一覧 {@see modules()} と同じ範囲・並び。
     *
     * @return array{module: array<string, mixed>, fieldUsage: array<string, int>, modules: list<ModuleSummary>, textMaxLengths: TextMaxLengths, headings: list<array{id: int, name: string, fieldCount: int}>, items: list<array{kind: 'heading', id: int}|array{kind: 'field', code: string}>}
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     */
    public function module(string $moduleCode): array
    {
        $module = $this->definitions->load(RecordModule::query()->where('code', $moduleCode)->firstOrFail());
        $parent = $module->parentRecordModule;
        $definition = $this->definitions->definition($module);

        return [
            'module' => [
                ...$definition,
                'fields' => array_map(
                    // 業務モジュールの中で一意のコードで項目を引く(定義の並びに頼らない)
                    fn (array $item) => $this->adminField(
                        $module->recordFields->firstOrFail('code', $item['code']),
                        $item,
                    ),
                    $definition['fields'],
                ),
                'parent' => $parent === null ? null : ['code' => $parent->code, 'name' => $parent->name],
                'isVisible' => $this->isVisible($module),
                'rowAccessRestrictedByDefault' => $this->rowAccessRestrictedByDefault($module),
            ],
            'fieldUsage' => $this->usage($module),
            'modules' => array_map(
                fn (RecordModule $item) => ['code' => $item->code, 'name' => $item->name],
                $this->ordered(RecordModule::query()->select(['id', 'code', 'name', 'parent_record_module_id'])),
            ),
            'textMaxLengths' => DefinitionText::maxLengths(),
            ...$this->headingGroups->adminProps($module->id),
        ];
    }

    /**
     * 業務モジュールの作成と編集で選べるアイコン(画面/管理の画面.md 3.2・3.5 の props icons。{@see MenuIcon::recordModuleChoices()} の順。先頭が作成の既定)。
     *
     * @return list<IconChoice>
     */
    private function iconChoices(): array
    {
        $choices = MenuIcon::recordModuleChoices();

        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($choices), $choices);
    }

    /**
     * 業務モジュールの作成の画面の props(画面/管理の画面.md 3.2)。
     *
     * @return array{icons: list<IconChoice>, textMaxLengths: TextMaxLengths}
     */
    public function createForm(): array
    {
        return ['icons' => $this->iconChoices(), 'textMaxLengths' => DefinitionText::maxLengths()];
    }

    /**
     * 業務モジュールの編集の画面の props(画面/管理の画面.md 3.5、モジュール分類/画面と検査.md 4.2)。
     *
     * @return array{module: array{code: string, name: string, description: string|null, icon: string|null, isVisible: bool|null, isDetails: bool, categoryIds: list<string>|null, isAttachmentEnabled: bool|null, rowAccessRestrictedByDefault: bool|null}, icons: list<IconChoice>, categories: list<CategoryChoice>, textMaxLengths: TextMaxLengths, visibilityStopReason: string|null}
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     */
    public function moduleForm(string $moduleCode): array
    {
        $module = RecordModule::query()->where('code', $moduleCode)->firstOrFail();
        $isDetails = $module->parent_record_module_id !== null;

        return [
            'module' => [
                'code' => $module->code,
                'name' => $module->name,
                'description' => $module->description,
                'icon' => $module->icon,
                'isVisible' => $this->isVisible($module),
                'isDetails' => $isDetails,
                'categoryIds' => $isDetails ? null : $this->categories->categoryIdsOf($module->code),
                'isAttachmentEnabled' => $isDetails ? null : $module->is_attachment_enabled,
                'rowAccessRestrictedByDefault' => $this->rowAccessRestrictedByDefault($module),
            ],
            'icons' => $this->iconChoices(),
            'categories' => $this->categories->choices(),
            'textMaxLengths' => DefinitionText::maxLengths(),
            'visibilityStopReason' => $isDetails ? null : $this->visibilityStops->stopReason($module->id),
        ];
    }

    /**
     * 管理の書き込みの口のルートの引数が指す行(業務モジュール(明細のモジュールを含む)・項目・規則・見出し)があるかを、入力の検証より前に確かめる
     * (FormRequest の authorize から。404 → 422 の順)。業務処理もあとで同じ行を引く。
     *
     * @throws ModelNotFoundException<RecordModule|RecordField|RecordRule|RecordFieldHeading> どれかが無いとき
     */
    public function findTargets(
        string $moduleCode,
        ?string $fieldCode = null,
        ?int $ruleId = null,
        ?int $headingId = null,
    ): void {
        $module = RecordModule::query()->where('code', $moduleCode)->firstOrFail(['id']);

        if ($fieldCode !== null) {
            RecordField::query()->where('record_module_id', $module->id)->where('code', $fieldCode)->firstOrFail(['id']);
        }

        if ($ruleId !== null) {
            RecordRule::query()->where('record_module_id', $module->id)->findOrFail($ruleId, ['id']);
        }

        if ($headingId !== null) {
            RecordFieldHeading::query()->where('record_module_id', $module->id)->findOrFail($headingId, ['id']);
        }
    }

    /**
     * 項目の追加・編集の画面の props(画面/管理の画面.md 3.4)。fieldCode が null なら追加。
     *
     * @return array{module: array{code: string, name: string, isDetails: bool}, field: array<string, mixed>|null, referableModules: list<ModuleSummary>, textMaxLengths: TextMaxLengths, stringMaxLengths: StringMaxLengths}
     *
     * @throws ModelNotFoundException<RecordModule|RecordField> 業務モジュール・項目が無いとき
     */
    public function fieldForm(string $moduleCode, ?string $fieldCode): array
    {
        $module = RecordModule::query()->where('code', $moduleCode)->firstOrFail();

        return [
            'module' => ['code' => $module->code, 'name' => $module->name, 'isDetails' => $module->parent_record_module_id !== null],
            'field' => $fieldCode === null ? null : $this->field($module, $fieldCode),
            'referableModules' => array_values(RecordModule::query()->whereNull('parent_record_module_id')->orderBy('sort_key')->orderBy('code')->get()
                ->map(fn (RecordModule $referable) => ['code' => $referable->code, 'name' => $referable->name])->all()),
            'textMaxLengths' => DefinitionText::maxLengths(),
            'stringMaxLengths' => [
                'singleLine' => FieldValueParser::SINGLE_LINE_MAX,
                'multiline' => FieldValueParser::MULTILINE_MAX,
                'multilineMax' => FieldValueParser::MULTILINE_MAX_LIMIT,
            ],
        ];
    }

    /**
     * 編集する項目の定義。編集の欄は、定義の maxLength(型の上限を含む実際の上限)でなく保存された属性(NULL = 型の上限)を使う
     * (画面/管理の画面.md 3.3)。項目 1 つとその選択肢・参照先・明細の子の定義だけを読む。
     *
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException<RecordField> 項目が無いとき
     */
    private function field(RecordModule $module, string $fieldCode): array
    {
        $field = RecordField::query()->where('record_module_id', $module->id)->where('code', $fieldCode)
            ->with(['recordFieldOptions' => fn (Relation $options) => $options->orderBy('sort_key')->orderBy('code'), 'referencedRecordModule', 'childRecordModule'])
            ->firstOrFail();

        if ($field->childRecordModule !== null) {
            $this->definitions->load($field->childRecordModule);
        }

        $definition = $this->adminField($field, $this->definitions->fieldDefinition($field));

        return [...$definition, 'maxLengthAttribute' => $field->max_length];
    }

    /**
     * 管理の画面の項目の形: 相対の既定値は値にせず長さのまま relativeDefault で渡し、defaultValue は null にする
     * (画面/管理の画面.md 3.4 の props の注。項目の表の既定値の札も長さから作る = 3.3)。
     *
     * @param  array<string, mixed>  $definition  {@see RecordDefinitionQuery::fieldDefinition()} の形
     * @return array<string, mixed>
     */
    private function adminField(RecordField $field, array $definition): array
    {
        $relative = $field->default_value instanceof RelativeDefault ? $field->default_value : null;

        return [
            ...$definition,
            'defaultValue' => $relative === null ? $definition['defaultValue'] : null,
            'relativeDefault' => $relative?->duration,
        ];
    }

    /**
     * 業務モジュールを一覧の並びにする(親の無いものを sort_key・code の順に、明細はその親の直後 = 設計/業務データの入れ物/データモデル/表.md 2.1)。並びの定義はここ 1 か所。
     *
     * @param  Builder<RecordModule>  $query
     * @return list<RecordModule>
     */
    private function ordered(Builder $query): array
    {
        $modules = $query->orderBy('sort_key')->orderBy('code')->get();
        $ordered = [];

        foreach ($modules->whereNull('parent_record_module_id') as $module) {
            $ordered[] = $module;

            foreach ($modules->where('parent_record_module_id', $module->id) as $child) {
                $ordered[] = $child;
            }
        }

        return $ordered;
    }

    /**
     * @param  list<string>  $visibleCodes  表示中の業務モジュールのコード
     * @return ModuleRow
     */
    private function row(RecordModule $module, ?string $parentCode, array $visibleCodes): array
    {
        return [
            'code' => $module->code,
            'name' => $module->name,
            'parentCode' => $parentCode,
            'isVisible' => $module->parent_record_module_id === null ? in_array($module->code, $visibleCodes, true) : null,
        ];
    }

    /**
     * 業務モジュール 1 つが表示中か(明細のモジュールは表示の状態を持たないので null)。
     */
    private function isVisible(RecordModule $module): ?bool
    {
        return $module->parent_record_module_id === null ? $this->visibility->isVisible($module->code) : null;
    }

    /**
     * 業務モジュール 1 つの参照制限の既定(明細のモジュールは持たないので null)。
     */
    private function rowAccessRestrictedByDefault(RecordModule $module): ?bool
    {
        return $module->parent_record_module_id === null ? $module->row_access_restricted_by_default : null;
    }

    /**
     * 項目のコード → 値を持つレコードの数(通常の項目 = field_values にキーを持つレコード、参照 = 参照の値の行を持つレコード(複数選べる参照の
     * 行を重ねて数えない)、明細 = 明細のモジュールの行)。
     * 種類ごとに 1 本の集計(通常の項目は jsonb_object_keys で数える。field_values は JSON の null を持たないので、キーがあれば値がある)。
     *
     * @return array<string, int>
     */
    private function usage(RecordModule $module): array
    {
        $keys = Record::query()->where('record_module_id', $module->id)->selectRaw('jsonb_object_keys(records.field_values) AS field_key');
        $plain = Record::query()->fromSub($keys, 'field_keys')->selectRaw('field_keys.field_key, count(*) AS total')->groupBy('field_keys.field_key')->pluck('total', 'field_key')->all();
        $references = RecordReference::query()->where('record_module_id', $module->id)->selectRaw('record_field_id, count(DISTINCT record_id) AS total')->groupBy('record_field_id')->pluck('total', 'record_field_id')->all();
        $childIds = array_values(array_filter($module->recordFields->pluck('child_record_module_id')->all()));
        $details = Record::query()->whereIn('record_module_id', $childIds)->selectRaw('record_module_id, count(*) AS total')->groupBy('record_module_id')->pluck('total', 'record_module_id')->all();
        $usage = [];

        foreach ($module->recordFields as $field) {
            $usage[$field->code] = (int) match (true) {
                $field->type === RecordFieldType::Details => $details[$field->child_record_module_id] ?? 0,
                $field->type->isReference() => $references[$field->id] ?? 0,
                default => $plain[(string) $field->id] ?? 0,
            };
        }

        return $usage;
    }
}
