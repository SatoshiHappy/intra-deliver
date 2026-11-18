<?php

namespace App\Actions\Ai\Core;

/**
 * 知らせの口(受け手。docs の 設計/AI/会話の中核の口と受け手.md 13.3 の「知らせの口」)。確定した物から順に、同じ処理の中で呼び、
 * 戻るまで先へ進まない。繋ぎが会話の保存に書くのは {@see AppendNotice} の項目だけ(設計/AI/エージェントの実行/口と契約.md 9.13 の口の足すだけで書く)。
 */
interface CoreNotifier
{
    public function notify(CoreNotice $notice): NoticeReply;
}
