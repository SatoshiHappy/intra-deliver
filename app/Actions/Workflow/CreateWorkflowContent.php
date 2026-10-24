<?php

namespace App\Actions\Workflow;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * コンテンツを作る(docs の 設計/ワークフロー/データモデル/表.md の 2.1 節。呼び手は 設計/ワークフロー/初期データ.md の 3 節)。
 * 業務モジュールと申請対象の項目はコードで指す(業務データの入れ物の口と同じ)。今は鍵を取らず、同時の作成は DB の一意・外部キーが最後に止める。
 * 拡張点(定義の書き込みを直列にするときは、ここで鍵を取る)。
 */
class CreateWorkflowContent
{
    use ValidatesWorkflowDefinition;

    /**
     * @param  string|null  $targetFieldCode  申請対象の項目のコード(null = 申請対象なし)
     * @return int 作ったコンテンツの id
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws ValidationException コード(code。書式・重複)・名前(name)・業務モジュールを指すコンテンツが既にある(module)・
     *                             申請対象の項目の誤り(target_field)
     */
    public function execute(string $moduleCode, string $code, string $name, ?string $targetFieldCode = null): int
    {
        $module = RecordModule::query()->where('code', $moduleCode)->whereNull('parent_record_module_id')
            ->firstOrFail();
        $code = $this->normalized($code);
        $name = $this->normalized($name);
        $targetFieldCode = $this->normalized($targetFieldCode);
        $targetField = $targetFieldCode === null ? null : $this->referenceField($module, $targetFieldCode);
        $this->throwIfAny([
            'code' => $this->codeError($code, fn (string $code) => WorkflowContent::query()->where('code', $code)
                ->exists()),
            'name' => $this->textError($name, '名前'),
            'module' => WorkflowContent::query()->where('record_module_id', $module->id)->exists()
                ? 'この業務モジュールは既にほかのコンテンツが使用しています' : null,
            'target_field' => $targetFieldCode !== null && $targetField === null
                ? '申請対象には、この業務モジュールの他モジュール参照の項目を指定してください' : null,
        ]);

        return DB::transaction(fn () => WorkflowContent::query()->create([
            'code' => $code,
            'name' => $name,
            'record_module_id' => $module->id,
            'target_record_field_id' => $targetField?->id,
            'target_record_module_id' => $targetField?->referenced_record_module_id,
        ])->id);
    }

    /**
     * 業務モジュールの、コードの他モジュール参照の項目(無い・型が違うときは null)。
     */
    private function referenceField(RecordModule $module, string $code): ?RecordField
    {
        return RecordField::query()->where('record_module_id', $module->id)->where('code', $code)
            ->where('type', RecordFieldType::Record)->first();
    }
}
