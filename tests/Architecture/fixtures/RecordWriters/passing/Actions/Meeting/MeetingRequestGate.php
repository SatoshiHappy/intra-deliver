<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Api\RunRequestMessages;

/**
 * 文だけを使う場所は依頼の口でない RunRequestMessages の定数を使う(依頼の口の一覧に足さない)
 */
final class MeetingRequestGate
{
    public function guestMessage(): string
    {
        return RunRequestMessages::GUEST;
    }
}
