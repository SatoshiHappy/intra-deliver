<?php

namespace App\Actions\Records;

use App\Actions\Upload\Api\DeleteUploads;
use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Queries\Records\RecordAttachmentFiles;
use App\Queries\Records\RecordHoldCatalog;
use Illuminate\Validation\ValidationException;

/**
 * レコードの削除の、行の鍵より後ろの本体(内部。判定する削除 {@see DeleteRecord} と判定しない削除 {@see Api\DeleteRecordUnchecked} が
 * 同じ 1 か所を通る = docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2、消える順 =
 * 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 6 節)。値は検証しない。
 * 呼び手が業務モジュールの共有の勧告ロックと行の FOR UPDATE を取り、トランザクションを持つ。
 */
class LockedRecordDeletion
{
    use ConvertsForeignKeyViolations;

    /** ほかの行から参照されていて消せないときの文言(失敗と定義の変更.md 10 節) */
    public const REFERENCED = 'このレコードはほかのデータから参照されているため削除できません';

    public function __construct(
        private RecordHoldCatalog $holds,
        private RecordAttachmentFiles $attachments,
        private DeleteUploads $deleteUploads,
    ) {}

    /**
     * 持ち主があれば拒み、レコードと添付を消す(消える順はクラスの説明の docs の節。実体はコミットの後)。
     *
     * @param  Record  $record  親の無いレコード(呼び手が FOR UPDATE で引いたもの)
     *
     * @throws ValidationException 持ち主があるとき・ほかの行から参照されているとき(キー record)
     */
    public function delete(Record $record): void
    {
        $hold = $this->holds->blockingHold($record->id, null);

        if ($hold !== null) {
            throw ValidationException::withMessages(['record' => [$hold->reason]]);
        }

        $uploadIds = $this->attachments->uploadIdsOf($record->record_module_id, $record->id);
        RecordAttachment::query()->where('record_module_id', $record->record_module_id)
            ->where('record_id', $record->id)->delete();
        $this->convertingForeignKeyViolations(
            fn () => Record::query()->whereKey($record->id)->delete(),
            'record',
            self::REFERENCED,
        );
        $this->deleteUploads->execute($uploadIds);
    }
}
