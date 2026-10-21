<?php

namespace App\Actions\Meeting;

/**
 * 作った会議と、AI が件名を書くか(作成のときに人が件名を入れなかった = docs の 設計/業務ツール/会議/README.md の 1.2)。
 */
final readonly class CreatedMeeting
{
    public function __construct(public int $recordId, public bool $writesTitle) {}
}
