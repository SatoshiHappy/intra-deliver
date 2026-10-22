<?php

namespace App\Actions\Records;

use App\Actions\Master\Api\CommonMasterWriteLock;
use App\Models\Records\RecordFieldHeading;
use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 見出しを消す(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/見出し.md の 4 節)。下の項目は前のまとまり(無ければ頭のまとまり)に入り、項目は消さない。
 * 見出しが無い・その業務モジュールのでないときは 404。共通マスタと業務モジュールの排他の勧告ロック({@see LocksRecordModule})の下で行う。
 */
class DeleteRecordFieldHeading
{
    use CommonMasterWriteLock;
    use LocksRecordModule;

    /**
     * @throws ModelNotFoundException<RecordModule|RecordFieldHeading> 業務モジュールが無い・見出しがその業務モジュールに無いとき
     */
    public function execute(string $moduleCode, int $headingId): void
    {
        $this->writeCommonMaster(function () use ($moduleCode, $headingId) {
            $module = $this->lockRecordModuleExclusively($moduleCode);
            RecordFieldHeading::query()->where('record_module_id', $module->id)->findOrFail($headingId)->delete();
        });
    }
}
