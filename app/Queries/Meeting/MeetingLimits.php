<?php

namespace App\Queries\Meeting;

/**
 * 会議の入口の上限の値の 1 か所(業務処理が確かめ、一覧と詳細の口が画面へ props で渡す = docs の 設計/業務ツール/会議/公開デモと画面.md の
 * 9.2・9.3)。値だけを持ち、判定は業務処理が持つ。
 */
final class MeetingLimits
{
    /** 取り込むテキスト(ファイル・貼り付け)の大きさの上限(MB。画面と 422 の文に出す数) */
    public const TEXT_MAX_MEGABYTES = 1;

    /** 取り込むテキストの大きさの上限(バイト。読む前に断る) */
    public const TEXT_MAX_BYTES = self::TEXT_MAX_MEGABYTES * 1_048_576;

    /** 再作成の追加の指示の最大の文字数 */
    public const INSTRUCTIONS_MAX_LENGTH = 500;
}
