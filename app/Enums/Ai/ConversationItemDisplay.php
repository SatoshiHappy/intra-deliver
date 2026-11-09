<?php

namespace App\Enums\Ai;

/**
 * 会話の保存の項目の、画面の履歴での出し方(docs の 設計/AI/エージェントの実行/口と契約.md 9.13 の「項目の種類」)。
 */
enum ConversationItemDisplay: string
{
    /** 出す */
    case Show = 'show';

    /** 出さない */
    case Hide = 'hide';

    /** 境目の 1 行として出す */
    case BoundaryLine = 'boundary_line';

    /** 理由を利用者の文として出す(承認待ちへの理由つきの断り) */
    case ReasonAsUserText = 'reason_as_user_text';
}
