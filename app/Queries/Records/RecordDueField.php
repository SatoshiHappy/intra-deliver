<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use LogicException;

/**
 * 期限の項目の引き方の 1 か所(期限が来たレコードの読み取り {@see Api\RecordDueQuery} と期限の削除の口が使う。docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 1.1 節)。呼び手は業務モジュールの定期の処理なので、
 * 業務モジュール・項目の誤りは利用者の入力でなく作りの誤り。
 */
class RecordDueField
{
    /**
     * @throws LogicException 業務モジュールが無い・明細のモジュールのとき(作りの誤り)
     */
    public function module(string $moduleCode): RecordModule
    {
        $module = RecordModule::query()->where('code', $moduleCode)->first()
            ?? throw new LogicException("業務モジュール {$moduleCode} が無い");

        return $module->parent_record_module_id === null
            ? $module
            : throw new LogicException("業務モジュール {$moduleCode} は明細のモジュール");
    }

    /**
     * @throws LogicException 項目が無い・日付の型でないとき(作りの誤り)
     */
    public function field(RecordModule $module, string $fieldCode): RecordField
    {
        $field = RecordField::query()->where('record_module_id', $module->id)->where('code', $fieldCode)->first()
            ?? throw new LogicException("業務モジュール {$module->code} に項目 {$fieldCode} が無い");

        return $field->type === RecordFieldType::Date
            ? $field
            : throw new LogicException("業務モジュール {$module->code} の項目 {$fieldCode} は日付の型でない");
    }
}
