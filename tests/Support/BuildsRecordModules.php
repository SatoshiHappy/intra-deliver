<?php

namespace Tests\Support;

use App\Actions\Authz\CreatePolicy;
use App\Actions\Authz\CreateResource;
use App\Actions\Authz\FindOrCreateSingleConditionSubjectGroup;
use App\Actions\Authz\SubjectConditionInput;
use App\Actions\Records\CreateRecordModule;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\PolicyEffect;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordField;
use App\Models\Records\RecordFieldOption;
use App\Models\Records\RecordModule;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;

/**
 * 業務データの入れ物のテストのデータを作る手助け(業務モジュールの行はテストで作り、認可はテストでポリシーを作る = docs の
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節)。基準日は 2026-10-11、ロケールは ja。
 */
trait BuildsRecordModules
{
    private const TODAY = '2026-10-11';

    /**
     * 親の無い業務モジュールと、その認可のリソース。ゲストと認証済みの全員に、渡したアクションを許す(既定は業務モジュールを作る業務処理の
     * 初期ポリシーと同じ 4 つ = 全件の参照を含まない)。
     *
     * @param  list<RecordModuleAction>  $permitted
     */
    protected function recordModule(string $code, ?array $permitted = null): RecordModule
    {
        $module = RecordModule::factory()->create(['code' => $code, 'name' => $code]);
        $resource = app(CreateResource::class)->execute(RecordModuleAction::resourceGroupCode($code), null, RecordModuleAction::RESOURCE_TYPE, RecordModuleAction::uri($code), []);

        foreach ([MetaSubject::Anonymous, MetaSubject::Authenticated] as $metaSubject) {
            $subjectGroup = app(FindOrCreateSingleConditionSubjectGroup::class)->execute(new SubjectConditionInput(metaSubject: $metaSubject), []);

            foreach ($permitted ?? CreateRecordModule::EVERYONE_ACTIONS as $action) {
                app(CreatePolicy::class)->execute($resource->authz_resource_group_id, $subjectGroup->id, RecordModuleAction::RESOURCE_TYPE, $action->value, PolicyEffect::Permit);
            }
        }

        return $module;
    }

    /**
     * 項目(名前はコードと同じ)。
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function field(RecordModule $module, string $code, RecordFieldType $type = RecordFieldType::String, array $attributes = []): RecordField
    {
        return RecordField::factory()->create(['record_module_id' => $module->id, 'code' => $code, 'name' => $code, 'type' => $type, ...$attributes]);
    }

    /**
     * 選択の項目と、その選択肢(並びは渡した順)。
     *
     * @param  list<string>  $codes
     * @param  array<string, mixed>  $attributes
     */
    protected function choiceField(RecordModule $module, string $code, array $codes, array $attributes = []): RecordField
    {
        $field = $this->field($module, $code, RecordFieldType::Choice, $attributes);

        foreach ($codes as $index => $optionCode) {
            RecordFieldOption::factory()->create(['record_field_id' => $field->id, 'code' => $optionCode, 'name' => "{$optionCode}の名称", 'sort_key' => $index]);
        }

        return $field;
    }

    /**
     * 他モジュール参照の項目。
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function referenceField(RecordModule $module, string $code, RecordModule $referenced, array $attributes = []): RecordField
    {
        return $this->field($module, $code, RecordFieldType::Record, ['referenced_record_module_id' => $referenced->id, ...$attributes]);
    }

    /**
     * 明細の項目と、その明細のモジュール(コード <項目のコード>_rows)。
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function detailsField(RecordModule $module, string $code, array $attributes = []): RecordField
    {
        $child = RecordModule::factory()->create(['code' => "{$code}_rows", 'name' => "{$code}_rows", 'parent_record_module_id' => $module->id]);

        return $this->field($module, $code, RecordFieldType::Details, ['child_record_module_id' => $child->id, ...$attributes]);
    }

    /**
     * 明細の項目の明細のモジュール。
     */
    protected function childOf(RecordField $detailsField): RecordModule
    {
        return RecordModule::query()->findOrFail($detailsField->child_record_module_id);
    }

    /**
     * 判定の入力(ユーザーなし = ゲスト)。
     */
    protected function contextFor(?int $userId = null): AccessContext
    {
        return new AccessContext($userId, null, null, CarbonImmutable::parse(self::TODAY), 'ja');
    }
}
