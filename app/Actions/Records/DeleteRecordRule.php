<?php

namespace App\Actions\Records;

use App\Actions\Master\WritesCommonMaster;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRule;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 規則を消す(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/失敗と定義の変更.md 11 節)。共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class DeleteRecordRule
{
    use LocksRecordModule;
    use WritesCommonMaster;

    /**
     * @throws ModelNotFoundException<RecordModule|RecordRule> 業務モジュールが無い・規則がその業務モジュールに無いとき
     */
    public function execute(string $moduleCode, int $ruleId): void
    {
        $this->writeCommonMaster(function () use ($moduleCode, $ruleId) {
            $module = $this->lockRecordModuleExclusively($moduleCode);
            RecordRule::query()->where('record_module_id', $module->id)->whereKey($ruleId)->firstOrFail();
            RecordRule::query()->whereKey($ruleId)->delete();
        });
    }
}
