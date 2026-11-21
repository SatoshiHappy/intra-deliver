<?php

namespace App\Actions\Ai\Core\Substitute;

use App\Actions\Ai\Core\AfterSendNotice;
use App\Actions\Ai\Core\CoreClock;
use App\Actions\Ai\Core\CoreNotice;
use App\Actions\Ai\Core\CoreNotifier;
use App\Actions\Ai\Core\NoticeReply;

/**
 * 送信の成功(送った後の知らせに終わりの理由がある = 応答が終わりまで届いた)の時刻を、思考の文脈の管理の最後の完了の時刻にする(docs の
 * 設計/AI/会話の中核.md 13.2)。ループと 1 回の送信のどの知らせもここを通るので、送信の種別を問わず 1 か所で受ける。
 */
final readonly class ThinkingNotices implements CoreNotifier
{
    public function __construct(
        private CoreNotifier $inner,
        private SubstituteThinking $thinking,
        private CoreClock $clock,
    ) {}

    public function notify(CoreNotice $notice): NoticeReply
    {
        $reply = $this->inner->notify($notice);

        if ($notice instanceof AfterSendNotice && $notice->finish !== null) {
            $this->thinking->completed($this->clock->now());
        }

        return $reply;
    }
}
