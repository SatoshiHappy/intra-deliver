<?php

/*
 * モジュール Meeting の配備の設定。purge_after = 会議の削除予定日の既定の期間(配備ごとに違う = docs の 設計/業務ツール/会議/通知と削除予定日.md の 7.1。
 * 受ける形 = {@see \App\Models\Records\RelativeDefault})。
 */

return [
    'purge_after' => env('MEETING_PURGE_AFTER', 'P6M'),
];
