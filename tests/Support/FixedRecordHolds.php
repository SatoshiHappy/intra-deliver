<?php

namespace Tests\Support;

use App\Models\Records\Record;
use App\Queries\Records\Api\RecordHold;
use App\Queries\Records\Api\RecordHolds;

/**
 * テストの中だけで使う持ち主の実装(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 4 節の口): 決めたレコードに決めた持ち主を置く。
 * firstHoldInModule は、その業務モジュールのレコードのうち持ち主を置いたもの(id の昇順の最初)の持ち主を返す。
 * holdsOf が呼ばれた回数を数える(1 描画で持ち主を何回読むかを確かめるため)。
 */
final class FixedRecordHolds implements RecordHolds
{
    private int $holdsOfCalls = 0;

    /**
     * @param  array<int, RecordHold>  $holds  レコードの id → 持ち主
     */
    public function __construct(private readonly array $holds) {}

    public function holdsOf(array $recordIds): array
    {
        $this->holdsOfCalls++;

        return array_intersect_key($this->holds, array_flip($recordIds));
    }

    /**
     * holdsOf が呼ばれた回数。
     */
    public function holdsOfCalls(): int
    {
        return $this->holdsOfCalls;
    }

    public function firstHoldInModule(int $recordModuleId): ?RecordHold
    {
        $recordId = Record::query()->whereKey(array_keys($this->holds))->where('record_module_id', $recordModuleId)->orderBy('id')->value('id');

        return $recordId === null ? null : $this->holds[$recordId];
    }
}
