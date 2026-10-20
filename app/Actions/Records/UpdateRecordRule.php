<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use App\Queries\Records\RecordDefinitionQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * 規則を変える(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節。種別は変えない。入力は種別のほかの全体で置き換える)。
 * 共通マスタの勧告ロックと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class UpdateRecordRule
{
    use LocksRecordModule;
    use NormalizesInput;
    use WritesCommonMaster;

    public function __construct(
        private RecordDefinitionQuery $definitions,
        private RuleDefinitionValidator $validator,
    ) {}

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ModelNotFoundException<RecordModule|RecordRule> 業務モジュールが無い・規則がその業務モジュールに無いとき
     * @throws ValidationException 規則に反するとき
     */
    public function execute(string $moduleCode, int $ruleId, array $input): RecordRule
    {
        $input = $this->normalizeInput($input);

        return $this->writeCommonMaster(function () use ($moduleCode, $ruleId, $input) {
            $module = $this->definitions->load($this->lockRecordModuleExclusively($moduleCode));
            $rule = RecordRule::query()->where('record_module_id', $module->id)->findOrFail($ruleId);
            $rule->update($this->validator->validate($module, $rule->kind, $input));

            return $rule;
        });
    }
}
