<?php

namespace Tests\Feature\Meeting;

use App\Actions\Records\Api\PurgeRecordMedia;
use DateTimeInterface;
use RuntimeException;

/**
 * 決めたレコードでだけ失敗する期限の削除の口(定期の処理が 1 件の失敗で残りを止めないことを確かめる)。ほかのレコードは本物の口を呼ぶ。
 */
class FailingPurgeRecordMedia extends PurgeRecordMedia
{
    /** @var list<int> 失敗させるレコード */
    public array $failingRecordIds = [];

    public function execute(string $moduleCode, int $recordId, string $dueFieldCode, DateTimeInterface $baseDate): int
    {
        if (in_array($recordId, $this->failingRecordIds, true)) {
            throw new RuntimeException("会議 {$recordId} の削除の失敗(テスト)");
        }

        return parent::execute($moduleCode, $recordId, $dueFieldCode, $baseDate);
    }
}
