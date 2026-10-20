<?php

namespace App\Actions\Workflow;

use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowContent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * コンテンツを作る(docs の 設計/ワークフロー/データモデル/表.md 2.1。呼び手は初期データ = 初期データ.md 3 節)。業務モジュールはコードで指す
 * (親の無いものだけ。明細のモジュールは無いものと同じ 404 = 業務データの入れ物の口と同じ。複合外部キーは最後の砦)。作った定義は変えない。自分のロックは取らない(定義の書き込みの直列は段 3 で決める)。
 */
class CreateWorkflowContent
{
    use ValidatesWorkflowDefinition;

    /**
     * @return int 作ったコンテンツの id
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき
     * @throws ValidationException コード(code。書式・重複)・名前(name)の誤り
     */
    public function execute(string $moduleCode, string $code, string $name): int
    {
        $module = RecordModule::query()->where('code', $moduleCode)->whereNull('parent_record_module_id')->firstOrFail();
        $code = $this->normalized($code);
        $name = $this->normalized($name);
        $this->throwIfAny([
            'code' => $this->codeError($code, fn (string $code) => WorkflowContent::query()->where('code', $code)->exists()),
            'name' => $this->textError($name, '名前'),
        ]);

        return DB::transaction(fn () => WorkflowContent::query()->create(['code' => $code, 'name' => $name, 'record_module_id' => $module->id])->id);
    }
}
