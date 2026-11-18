<?php

namespace Tests\Support;

use App\Queries\Records\Api\RecordHoldNotices;

/**
 * テストの持ち主の案内の実装({@see FixedRecordHoldNotices})を、起動と同じタグ {@see RecordHoldNotices::TAG} で登録する手助け。
 */
trait RegistersRecordHoldNotices
{
    private int $registeredRecordHoldNotices = 0;

    /**
     * 案内の実装を 1 つ登録して返す(呼ぶたびに登録の順で後ろに足す)。
     */
    protected function registerRecordHoldNotices(FixedRecordHoldNotices $notices): FixedRecordHoldNotices
    {
        $name = 'test.record-hold-notices.'.$this->registeredRecordHoldNotices++;
        $this->app->instance($name, $notices);
        $this->app->tag([$name], RecordHoldNotices::TAG);

        return $notices;
    }
}
