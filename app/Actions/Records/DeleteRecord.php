<?php

namespace App\Actions\Records;

use App\Actions\Upload\Api\DeleteUploads;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordAttachmentFiles;
use App\Queries\Records\RecordHoldCatalog;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordRowAccess;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * レコードを削除する(docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2。値は検証しない。明細の行と参照の値の行は連鎖で消える)。
 * 業務モジュールの勧告ロックを共有で取り({@see LocksRecordModule})、判定は業務モジュールの delete の後に、レコードの行を FOR UPDATE で引く
 * (行の判定 {@see RecordRowAccess} の条件で引き、通らなければ 404 = 認可とメニュー/README.md 2.4)。
 * 続けて持ち主の規則を確かめる(9.2 の 2): 削除は鍵を受けず、持ち主があればいつも拒む(キー record、文言 = 持ち主の理由。{@see RecordHoldCatalog::blockingHold()})。
 * ほかの行から参照されていて消せないときは、DELETE の文の
 * 外部キー違反を項目 record の検証の誤りに変える(設計/業務データの入れ物/データモデル/制約の分担と検査.md 6 節の例外 1)。PostgreSQL は ON DELETE RESTRICT の違反を
 * SQLSTATE 23001(restrict_violation)、NO ACTION の違反を 23503(foreign_key_violation)で返すので、両方を変える。
 * 添付の行は外部キーが先に消すことを求め、版が指していた実体も消す(docs の
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 6 節)。
 */
class DeleteRecord
{
    use LocksRecordModule;

    /** 外部キー違反の SQLSTATE(restrict_violation と foreign_key_violation) */
    private const FOREIGN_KEY_VIOLATIONS = ['23001', '23503'];

    public function __construct(
        private RecordModuleGate $gate,
        private RecordHoldCatalog $holds,
        private RecordRowAccess $rows,
        private RecordAttachmentFiles $attachments,
        private DeleteUploads $deleteUploads,
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

        DB::transaction(function () use ($context, $moduleCode, $recordId, $requester) {
            $module = $this->gate->topLevelModule($moduleCode);
            $this->lockRecordModuleShared($module);
            $this->gate->authorize($requester, $module, RecordModuleAction::Delete, $context->date);
            $records = Record::query()->where('record_module_id', $module->id);
            $this->rows->constrain($records, $requester, $module, $context->date);
            $record = $records->lockForUpdate()->findOrFail($recordId);
            $hold = $this->holds->blockingHold($record->id, null);

            if ($hold !== null) {
                throw ValidationException::withMessages(['record' => [$hold->reason]]);
            }

            $uploadIds = $this->attachments->uploadIdsOf($record->id);
            RecordAttachment::query()->where('record_id', $record->id)->delete();

            try {
                Record::query()->whereKey($record->id)->delete();
            } catch (QueryException $exception) {
                throw in_array($exception->getCode(), self::FOREIGN_KEY_VIOLATIONS, true)
                    ? ValidationException::withMessages(['record' => ['このレコードはほかのデータから参照されているため削除できません']])
                    : $exception;
            }

            $this->deleteUploads->execute($uploadIds);
        });
    }
}
