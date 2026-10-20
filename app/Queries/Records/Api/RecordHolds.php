<?php

namespace App\Queries\Records\Api;

use App\Queries\Records\RecordHoldCatalog;

/**
 * レコードの持ち主の口(依存の逆転 = docs の 設計/コード構成/README.md 3 節。口の形の正本 = 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の表、
 * 規則の正本 = 設計/業務データの入れ物/項目と規則/規則と業務処理/README.md 9.2 の 2、失敗と定義の変更.md 11 節)。持ち主のあるレコードの値は、持ち主の鍵を渡した書き込みでしか変わらない。
 *
 * 後ろのモジュール(ワークフロー)が実装し、起動(App\Providers)がタグ {@see self::TAG} で登録する。業務データの入れ物は実装を直接呼ばず、
 * 束ねる 1 か所(内部の {@see RecordHoldCatalog})を通す。
 */
interface RecordHolds
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'records.record-holds';

    /**
     * 渡したレコード(親の無いもの)のうち、持ち主のあるものの持ち主。鍵を取らずに読む
     *
     * @param  list<int>  $recordIds
     * @return array<int, RecordHold> レコードの id → 持ち主
     */
    public function holdsOf(array $recordIds): array;

    /** その業務モジュールのレコードの持ち主を 1 つ。無ければ null。鍵を取らずに読む(明細のモジュールなら呼び手が親の id を渡す) */
    public function firstHoldInModule(int $recordModuleId): ?RecordHold;
}
