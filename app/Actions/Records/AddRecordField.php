<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordDefinitionQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

/**
 * 項目を足す(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節。表の変更も配備も無く、行を足すだけ。既存のレコードは書き換えない)。
 * 並び順(sort_key)が入力に無ければ、その業務モジュールの最後(最大 + 10。{@see FieldDefinitionValidator})。
 * 型「明細」の項目は、同じトランザクションで明細のモジュール(親 = この業務モジュール)を作る(6 節)。選択肢の並びは入力の配列の順。
 * 定義の書き込みなので共通マスタと業務モジュールの排他の勧告ロックの下で行う(設計/業務データの入れ物/データモデル/値の形と直列化.md 4 節、{@see LocksRecordModule})。
 * 入力は {@see FieldDefinitionValidator}。
 */
class AddRecordField
{
    use LocksRecordModule;
    use NormalizesInput;
    use WritesCommonMaster;

    public function __construct(
        private RecordDefinitionQuery $definitions,
        private FieldDefinitionValidator $validator,
    ) {}

    /**
     * @param  string  $moduleCode  業務モジュール(明細のモジュールを含む)のコード
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $moduleCode, array $input): RecordField
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($moduleCode, $input) {
            $module = $this->definitions->load($this->lockRecordModuleExclusively($moduleCode));
            $validated = $this->validator->validateNew($module, $input);
            // 明細のモジュールは添付を使用しない(列の既定は true で、書かないと検査制約で落ちる = 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.8 節)
            $child = $validated['child'] === null ? null : RecordModule::query()->create([
                ...$validated['child'],
                'parent_record_module_id' => $module->id,
                'is_attachment_enabled' => false,
            ]);
            $field = RecordField::query()->create([
                ...$validated['attributes'],
                'record_module_id' => $module->id,
                'referenced_record_module_id' => $validated['referencedModuleId'],
                'child_record_module_id' => $child?->id,
            ]);

            foreach ($validated['options'] as $index => $option) {
                $field->recordFieldOptions()->create([...$option, 'sort_key' => $index]);
            }

            return $field->type === RecordFieldType::Choice ? $field->load(['recordFieldOptions' => fn (Relation $options) => $options->orderBy('sort_key')->orderBy('code')]) : $field;
        });
    }
}
