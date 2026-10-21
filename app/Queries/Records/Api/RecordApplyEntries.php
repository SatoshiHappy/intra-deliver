<?php

namespace App\Queries\Records\Api;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordApplyEntryCatalog;
use DateTimeInterface;

/**
 * レコードの詳細の「申請する」の入口を答える口(依存の逆転 = docs の 設計/コード構成/README.md 3 節。口の形の正本 =
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 2 節)。
 *
 * 後ろのモジュール(ワークフロー)が実装し、起動(App\Providers)がタグ {@see self::TAG} で登録する。業務データの入れ物は実装を直接呼ばず、
 * 束ねる 1 か所(内部の {@see RecordApplyEntryCatalog})を通す。
 */
interface RecordApplyEntries
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'records.record-apply-entries';

    /**
     * このレコードから申請できる入口(判定を求める者と、その者を申請の代理先とする代理元のどれかが申請できるもの)。
     * 持ち主の有無を見ない(押せない形は呼び手が持ち主で決める)。呼び手は業務モジュールの read を済ませてから呼ぶ。
     *
     * @return list<RecordApplyEntry> 無ければ空(無いレコードも空)
     */
    public function entriesFor(Requester $requester, int $recordId, DateTimeInterface $date): array;
}
