<?php

namespace App\Actions\Meeting;

use Illuminate\Support\Facades\DB;

/**
 * 会議ごとの勧告ロック(再作成と文字起こしの完了を会議ごとに直列にする。鍵の順 = 会議ごとの鍵 → AI の費用の鍵 → AI の実行の行 =
 * docs の 設計/業務データの入れ物/レコードの共通機能/データモデル/README.md の 4 節)。呼び手のトランザクションの終わりで外れる。
 */
class MeetingLock
{
    /** 鍵の起点(ほかの勧告ロックの鍵と重ならない空間に、会議の id を足す) */
    private const KEY_BASE = 4_500_000_000_000_000;

    public function lock(int $recordId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(?)', [self::KEY_BASE + $recordId]);
    }
}
