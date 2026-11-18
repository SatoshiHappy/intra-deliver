<?php

namespace App\Actions\Meeting;

use App\Actions\Ai\Core\AllowedContract;

/**
 * Ai の外のモジュールが中核の約束を参照するのは禁止(① と ② は ③ の口を使う)
 */
final class MeetingUsesCore
{
    public function __construct(public AllowedContract $contract) {}
}
