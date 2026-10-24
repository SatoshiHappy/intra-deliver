<?php

namespace Tests\Support;

use App\Queries\Records\Api\RecordHold;
use App\Queries\Records\Api\RecordHolds;

/**
 * テストの持ち主の実装({@see FixedRecordHolds})を、起動と同じタグ {@see RecordHolds::TAG} で登録する手助け。
 */
trait RegistersRecordHolds
{
    /** 拒むときの文言(ワークフローの実装と同じ文言 = docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 4 節) */
    private const HOLD_REASON = '申請済みのため変更できません';

    private int $registeredRecordHolds = 0;

    /**
     * 持ち主の実装を 1 つ登録して返す(呼ぶたびに登録の順で後ろに足す)。
     *
     * @param  array<int, string>  $holders  レコードの id → 持ち主の鍵(文言はどれも同じ)
     */
    protected function registerRecordHolds(array $holders, string $reason = self::HOLD_REASON): FixedRecordHolds
    {
        $name = 'test.record-holds.'.$this->registeredRecordHolds++;
        $recordHolds = new FixedRecordHolds(array_map(fn (string $holder) => new RecordHold($holder, $reason), $holders));
        $this->app->instance($name, $recordHolds);
        $this->app->tag([$name], RecordHolds::TAG);

        return $recordHolds;
    }
}
