<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\RecordInput;
use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Actions\Records\Api\UpdateRecord;
use App\Actions\Records\Api\UpdateRecordUnchecked;
use App\Enums\Records\RecordRevisionAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordHoldCatalog;
use App\Support\AccessContext;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use LogicException;

/**
 * レコードの更新の、行の鍵より後ろの本体(内部。判定する更新 {@see UpdateRecord} と判定しない更新 {@see UpdateRecordUnchecked} が
 * 同じ 1 か所を通る = docs の 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2〜9)。持ち主の規則を確かめる
 * {@see self::throwIfHeld()} と、3〜9・添付・更新履歴の版を書く {@see self::write()} に分ける(間に呼び手の版の比べと入力の確かめが入る)。
 * 呼び手が業務モジュールの共有の勧告ロックと行の FOR NO KEY UPDATE を取り、トランザクションを持つ。
 */
class LockedRecordUpdate
{
    public function __construct(
        private RecordSaver $saver,
        private RecordHoldCatalog $holds,
        private RecordAttachmentGate $attachmentGate,
        private RecordAttachmentWriter $attachments,
        private RecordRevisionWriter $revisions,
    ) {}

    /**
     * 渡した鍵と違う持ち主があれば拒む(9.2 の 2 の持ち主の規則)。鍵を渡したのに、そのレコードの持ち主にその鍵が無ければ呼び手の誤り
     * (鍵だけで行の判定を飛ばすので = 9.2 の 2 の更新と添付の結果の表。添付の口 {@see RecordAttachmentGate::ensureWritable()} と同じ)。
     *
     * @param  string|null  $holder  持ち主の鍵(null = 鍵を渡さない = 持ち主があれば拒む)
     *
     * @throws ValidationException 渡した鍵と違う持ち主があるとき(キー record)
     * @throws InvalidArgumentException 鍵を渡したのに、そのレコードの持ち主にその鍵が無いとき
     */
    public function throwIfHeld(Record $record, ?string $holder): void
    {
        $hold = $this->holds->blockingHold($record->id, $holder);

        if ($hold !== null) {
            throw ValidationException::withMessages(['record' => [$hold->reason]]);
        }

        if ($holder !== null && ! $this->holds->isHeldBy($record->id, $holder)) {
            throw new InvalidArgumentException("レコード {$record->id} の持ち主に鍵 {$holder} が無い");
        }
    }

    /**
     * 値を検証して書き(3〜9)、入力の添付を付け、更新履歴の版(update)を書く(順 = {@see UpdateRecord} の説明)。
     *
     * @param  Requester|null  $requester  参照先の判定を求める者(null = 判定しない更新)
     * @param  RecordModule  $module  定義を読み込んだ親の無い業務モジュール
     * @param  Record  $record  呼び手が FOR NO KEY UPDATE で引いた、その業務モジュールのレコード
     *
     * @throws ValidationException 検証に失敗したとき・添付を使用しない業務モジュールに添付を渡したとき(キー attachments)
     * @throws InvalidArgumentException 上げ済みの実体が持ち主の無い実体でないとき
     * @throws LogicException 判定を求める者が null で、他モジュール参照の相手の確かめに届いたとき(作りの誤り)
     */
    public function write(
        ?Requester $requester,
        AccessContext $context,
        RecordModule $module,
        Record $record,
        RecordInput $input,
        ?RecordRevisionOrigin $origin,
    ): void {
        $this->attachmentGate->ensureEnabledFor($module, $input);
        $record->load('recordReferences');

        $this->saver->save($requester, $context->date, $context->userId, $module, $record, $input);
        $this->attachments->attachInput($record, $origin->executorUserId ?? $context->userId, $input);
        $this->revisions->write($record->id, RecordRevisionAction::Update, $context->userId, $origin);
    }
}
