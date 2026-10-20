<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use App\Queries\Records\RecordDefinitionQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 規則を作る(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 8 節、失敗と定義の変更.md 11 節。
 * 既存のレコードは書き換えない)。入力は {@see RuleDefinitionValidator}。共通マスタと業務モジュールの排他の勧告ロックの下で行う
 * (設計/業務データの入れ物/データモデル/値の形と直列化.md 4 節、{@see LocksRecordModule})。
 */
class AddRecordRule
{
    use LocksRecordModule;
    use NormalizesInput;
    use WritesCommonMaster;

    public function __construct(
        private RecordDefinitionQuery $definitions,
        private RuleDefinitionValidator $validator,
    ) {}

    /**
     * @param  string  $moduleCode  業務モジュール(明細のモジュールを含む)のコード
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $moduleCode, array $input): RecordRule
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($moduleCode, $input) {
            $module = $this->definitions->load($this->lockRecordModuleExclusively($moduleCode));

            return RecordRule::query()->create([...$this->validator->validate($module, null, $input), 'record_module_id' => $module->id]);
        });
    }
}
