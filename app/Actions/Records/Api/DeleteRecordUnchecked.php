<?php

namespace App\Actions\Records\Api;

use App\Actions\Records\DeleteRecord;
use App\Actions\Records\LockedRecordDeletion;
use App\Actions\Records\LocksRecordModule;
use App\Actions\Records\RecordWriteTransaction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Records\RecordQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * レコードを判定せずに削除する公開の口(案件の削除で申請書のレコードを一緒に消す = ADR 0025 の決定 8。規則 = docs の
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2、保証の正本 = 設計/ワークフロー/業務データとの接続/公開の口.md
 * 7 節の表)。業務モジュールと行の判定だけを行わず、鍵と行の鍵より後ろは判定する削除 {@see DeleteRecord} と同じ。
 */
class DeleteRecordUnchecked
{
    use LocksRecordModule;
    use RecordWriteTransaction;

    public function __construct(private RecordQuery $records, private LockedRecordDeletion $deletion) {}

    /**
     * 主体の判定をしない。呼び手が操作の判定を済ませてから呼ぶ(保存期間の自動削除は期限を過ぎたレコードで呼ぶ)。
     *
     * 業務モジュールはレコードから引く(作成者・更新者を書かないので主体が要らない)。呼び手は自分の持ち主を先に外す(持ち主があれば拒む)。
     * 外側のトランザクションの中では savepoint になり、デッドロックを含む例外はやり直さずに伝わる(やり直すかは呼び手が決める)。
     *
     * @throws ModelNotFoundException<Record|RecordModule> レコードが無い・明細の行のとき
     * @throws ValidationException 持ち主があるとき・ほかの行から参照されているとき(キー record)
     */
    public function execute(int $recordId): void
    {
        $this->writeRecords(function () use ($recordId): void {
            $found = $this->records->topLevel($recordId);
            $this->lockRecordModuleShared(RecordModule::query()->findOrFail($found->record_module_id));
            $this->deletion->delete(Record::query()->lockForUpdate()->findOrFail($found->id));
        });
    }
}
