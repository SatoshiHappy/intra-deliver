<?php

namespace Tests\Support;

use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\Api\NoticeTargets;

/**
 * テストの移り先の実装({@see FixedNoticeTargets})を、起動と同じタグ {@see NoticeTargets::TAG} で登録する手助け。
 */
trait RegistersNoticeTargets
{
    /** テストの対象の種類 */
    private const TEST_TARGET_TYPE = 'test-target';

    /** テストの対象が見えないときの 1 行 */
    private const TEST_UNAVAILABLE_TEXT = 'このテストの対象は閲覧できません。';

    private int $registeredNoticeTargets = 0;

    /**
     * 移り先の実装を 1 つ登録して返す(呼ぶたびに登録の順で後ろに足す)。
     *
     * @param  array<int, NoticeTargetLink>  $links  見える対象の id → 移り先
     * @param  list<string>  $targetTypes
     */
    protected function registerNoticeTargets(
        array $links,
        array $targetTypes = [self::TEST_TARGET_TYPE],
        string $unavailableText = self::TEST_UNAVAILABLE_TEXT,
    ): FixedNoticeTargets {
        $name = 'test.notice-targets.'.$this->registeredNoticeTargets++;
        $noticeTargets = new FixedNoticeTargets($targetTypes, $links, $unavailableText);
        $this->app->instance($name, $noticeTargets);
        $this->app->tag([$name], NoticeTargets::TAG);

        return $noticeTargets;
    }
}
