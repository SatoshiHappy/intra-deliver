<?php

namespace App\Queries\Records\Api;

use App\Queries\Records\RecordHoldCatalog;

/**
 * 会議の再作成が、会議に持ち主(申請済み)があるかを読む口(持ち主の束ね方は {@see RecordHoldCatalog} の 1 か所。使い手 = docs の
 * 設計/業務ツール/会議/処理.md 3.7、消す時 = docs の 設計/adr/0025 の決定 9)。主体の判定をしない(呼び手が閲覧を判定済み)。鍵を取らずに読む。
 */
class RecordHoldQuery
{
    public function __construct(private RecordHoldCatalog $holds) {}

    /**
     * レコード(親の無いもの)に持ち主があるか(どの鍵も持たない書き込みを拒む持ち主 = 人の操作と AI の書き込みが断られる)。
     */
    public function isHeld(int $recordId): bool
    {
        return $this->holds->blockingHold($recordId, null) !== null;
    }
}
