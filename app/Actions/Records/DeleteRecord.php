<?php

namespace App\Actions\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordRowAccess;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * レコードを削除する(判定する削除。docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2。業務データの入れ物の画面の口)。
 * 業務モジュールの勧告ロックを共有で取り({@see LocksRecordModule})、判定は業務モジュールの delete の後に、レコードの行を FOR UPDATE で引く
 * (行の判定 {@see RecordRowAccess} の条件で引き、通らなければ 404 = docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4)。行の鍵より後ろ(持ち主・添付・
 * 参照されているときの 422・実体)は判定しない削除と同じ {@see LockedRecordDeletion}。
 */
class DeleteRecord
{
    use LocksRecordModule;
    use RecordWriteTransaction;

    public function __construct(
        private RecordModuleGate $gate,
        private RecordRowAccess $rows,
        private LockedRecordDeletion $deletion,
    ) {}

    /**
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュールが無い・明細のモジュール、レコードがその業務モジュールに無い・
     *                                                     行の判定を通らないとき
     * @throws AuthorizationException 削除を許されないとき
     * @throws ValidationException 持ち主があるとき・ほかの行から参照されているとき(キー record)
     */
    public function execute(AccessContext $context, string $moduleCode, int $recordId): void
    {
        $requester = Requester::fromAccessContext($context);

        $this->writeRecords(function () use ($context, $moduleCode, $recordId, $requester) {
            $module = $this->gate->topLevelModule($moduleCode);
            $this->lockRecordModuleShared($module);
            $this->gate->authorize($requester, $module, RecordModuleAction::Delete, $context->date);
            $records = Record::query()->where('record_module_id', $module->id);
            $records = $this->rows->constrain($records, $requester, $module, $context->date);
            $this->deletion->delete($records->lockForUpdate()->findOrFail($recordId));
        });
    }
}
