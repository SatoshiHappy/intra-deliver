<?php

namespace App\Enums\Records;

/**
 * レコードの共通機能が送る通知の種類(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 3 節。値 = 表 notices の type)。
 * 値ごとの文の雛形は画面の resources/js/lib/Notice/noticeText.ts に置く(値を足すときは雛形の case も足す)。
 */
enum RecordNoticeType: string
{
    /** コメントの宛先に指定された */
    case Comment = 'records.comment';
}
