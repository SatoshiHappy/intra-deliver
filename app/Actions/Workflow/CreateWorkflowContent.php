<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\HideRecordModuleUnchecked;
use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * コンテンツを作る(docs の 設計/ワークフロー/データモデル/表.md の 2.1 節。呼び手は 設計/ワークフロー/初期データ.md の 3 節)。
 * 業務モジュールと申請対象の項目はコードで指す(業務データの入れ物の口と同じ)。
 * コンテンツは業務モジュールの表示の止めを立てるので、止めを書く前に非表示の口({@see HideRecordModuleUnchecked})で鍵を取り、その下で検証する
 * (ADR 0031。手順と失敗の優先順 = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の「表示の止めを立てる側の保証」、
 * 鍵の順 = 設計/ワークフロー/データモデル/直列化と検査.md 3.2 の「コンテンツを作る業務処理」)。
 */
class CreateWorkflowContent
{
    use ValidatesWorkflowDefinition;

    public function __construct(private HideRecordModuleUnchecked $hideModule) {}

    /**
     * @param  string|null  $targetFieldCode  申請対象の項目のコード(null = 申請対象なし)
     * @return int 作ったコンテンツの id
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws ValidationException 拒むとき(業務データとの接続/公開の口.md 7 節)
     */
    public function execute(string $moduleCode, string $code, string $name, ?string $targetFieldCode = null): int
    {
        return DB::transaction(function () use ($moduleCode, $code, $name, $targetFieldCode) {
            $module = RecordModule::query()->where('code', $moduleCode)->whereNull('parent_record_module_id')
                ->firstOrFail();
            $this->hideModule->execute($module->code);

            return $this->createUnderLock($module, $code, $name, $targetFieldCode);
        });
    }

    /**
     * 業務モジュールの鍵の下で、入力を確かめて(当たるキーをまとめて 422)コンテンツを書き、その id を返す。
     *
     * @throws ValidationException 入力が規則に反するとき
     */
    private function createUnderLock(RecordModule $module, string $code, string $name, ?string $targetFieldCode): int
    {
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

        return WorkflowContent::query()->create([
            'code' => $code,
            'name' => $name,
            'record_module_id' => $module->id,
            'target_record_field_id' => $targetField?->id,
            'target_record_module_id' => $targetField?->referenced_record_module_id,
        ])->id;
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
