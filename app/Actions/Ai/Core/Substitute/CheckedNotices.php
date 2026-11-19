<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\AppendNotice;
use App\Actions\Ai\Core\CoreNotice;
use App\Actions\Ai\Core\CoreNotifier;
use App\Actions\Ai\Core\NoticeReply;
use LogicException;

/**
 * 知らせの口の返り値の確かめ(docs の 設計/AI/会話の中核の口と受け手.md 13.3 の知らせの口): 追記の知らせのほかの知らせに、会話が無い・
 * 重なりが返ったら作りの誤り。1 回の送信とループの受け手の知らせの口をこれで包む。
 */
final readonly class CheckedNotices implements CoreNotifier
{
    public function __construct(private CoreNotifier $inner) {}

    public function notify(CoreNotice $notice): NoticeReply
    {
        $reply = $this->inner->notify($notice);
        $unexpected = $reply->conversationGone || $reply->duplicateCallIds !== [];

        if ($unexpected && ! $notice instanceof AppendNotice) {
            throw new LogicException('追記の知らせのほかの知らせ '.$notice::class.' に、会話が無いか重なりが返った');
        }

        return $reply;
    }
}
