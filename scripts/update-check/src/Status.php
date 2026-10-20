<?php

namespace UpdateCheck;

/**
 * 結果の 1 行の区分(設計/更新チェック.md 1 節)。確認できなかったものは行でなく {@see Unconfirmed} で持つ。
 * Unfixed = 土台で未修正の脆弱性(上げても直らない・上げる先が無い。先頭行に別に数える)。
 */
enum Status: string
{
    case Action = '要対応';
    case Update = '更新あり';
    case Info = '表示だけ';
    case Unfixed = '土台で未修正';
}
