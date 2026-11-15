<?php

namespace App\Queries\Meeting;

use App\Enums\Meeting\MeetingNoticeType;
use App\Queries\Notice\Api\NoticeTypeEntry;
use App\Queries\Notice\Api\NoticeTypes;

/**
 * 会議の通知の種類の一覧(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 1 節)。
 * どれも切り替えられる(結果は会議のレコードで見られる)。起動がタグ {@see NoticeTypes::TAG} で登録する。
 */
class MeetingNoticeTypes implements NoticeTypes
{
    public function types(): array
    {
        return array_map(
            fn (MeetingNoticeType $type): NoticeTypeEntry => new NoticeTypeEntry($type->value, true),
            MeetingNoticeType::cases(),
        );
    }
}
