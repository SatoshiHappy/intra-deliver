<?php

namespace App\Actions\Ai\Api;

use App\Actions\Records\Api\UpdateRecord;

/**
 * 書き込みの口と同じ名前空間のほかのクラス(一覧の class はそのクラスだけで、名前空間を通さない)
 */
final class WritesBesideTheWriter
{
    public function __construct(private UpdateRecord $updateRecord) {}

    public function updateRecord(): UpdateRecord
    {
        return $this->updateRecord;
    }
}
