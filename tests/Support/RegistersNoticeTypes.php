<?php

namespace Tests\Support;

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
     * @param  list<string>  $types  答えの順
     */
    protected function registerNoticeTypes(array $types): FixedNoticeTypes
    {
        $name = 'test.notice-types.'.$this->registeredNoticeTypes++;
        $noticeTypes = new FixedNoticeTypes($types);
        $this->app->instance($name, $noticeTypes);
        $this->app->tag([$name], NoticeTypes::TAG);

        return $noticeTypes;
    }
}
