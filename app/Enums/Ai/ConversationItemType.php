<?php

namespace App\Enums\Ai;

/**
 * 会話の保存の項目の種類(docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」)。
 * 項目の中身の形は Ai の公開の口の項目の型(ConversationItem)が種類ごとに定める。
 */
enum ConversationItemType: string
{
    /** 利用者の文 */
    case UserText = 'user_text';

    /** モデルの文(確定したブロックの列。道具の呼び出しを含む。会話の中核が振る応答の id を持つ) */
    case ModelText = 'model_text';

    /** 応答の終わり(使用量・生の状態・作った接続先とモデル・ブロックの数と呼び出しの id の列・受け終えた時刻。画面にもモデルにも出さない) */
    case ResponseEnd = 'response_end';

    /** 取り消しの印(応答の id。その応答の項目を送らず出さない物にする) */
    case Cancellation = 'cancellation';

    /** 道具の結果(呼び出しの id・定型の種類と値・本文・失敗の印。理由つきの断りでは理由を値に含む) */
    case ToolResult = 'tool_result';

    /** 中断の印(道具の途中か否か) */
    case Interruption = 'interruption';

    /** 回数の上限の印(画面の履歴に出し、モデルには送らない) */
    case TurnLimit = 'turn_limit';

    /** 足す文(日付が変わった知らせ・結果の分からない書き込みの知らせ。定型の種類と値を持つ) */
    case AddedText = 'added_text';

    /** 要約の境目(要約の前の送る量の見積もりと要約が済んだ日を持つ。モデルには送らない) */
    case CompactBoundary = 'compact_boundary';

    /** 要約の文 */
    case CompactSummary = 'compact_summary';
}
