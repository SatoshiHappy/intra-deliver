<?php

namespace Tests\Support;

use App\Queries\Notice\Api\NoticeTypeEntry;
use App\Queries\Notice\Api\NoticeTypes;

/**
 * テストの種類の一覧の実装({@see FixedNoticeTypes})を、起動と同じタグ {@see NoticeTypes::TAG} で登録する手助け。
 * 種類の一覧を束ねる前(送る口・書き込みを最初に呼ぶ前)に登録する(束ねるのは 1 要求に 1 回)。
 */
trait RegistersNoticeTypes
{
    private int $registeredNoticeTypes = 0;

    /**
     * 種類の一覧の実装を 1 つ登録して返す(呼ぶたびに登録の順で後ろに足す)。
     *
     * @param  array<string, bool>  $types  種類 → 切り替えられるか(答えの順)
     */
    protected function registerNoticeTypes(array $types): FixedNoticeTypes
    {
        $entries = [];

        foreach ($types as $type => $switchable) {
            $entries[] = new NoticeTypeEntry($type, $switchable);
        }

        return $this->registerNoticeTypeEntries($entries);
    }

    /**
     * 答えをそのまま渡して登録する(同じ値を 2 度答える実装を作るため)。
     *
     * @param  list<NoticeTypeEntry>  $entries
     */
    protected function registerNoticeTypeEntries(array $entries): FixedNoticeTypes
    {
        $name = 'test.notice-types.'.$this->registeredNoticeTypes++;
        $noticeTypes = new FixedNoticeTypes($entries);
        $this->app->instance($name, $noticeTypes);
        $this->app->tag([$name], NoticeTypes::TAG);

        return $noticeTypes;
    }
}
