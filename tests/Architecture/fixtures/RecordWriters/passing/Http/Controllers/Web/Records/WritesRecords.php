<?php

namespace App\Http\Controllers\Web\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\UpdateRecord;

/**
 * 一覧の namespace(業務データの入れ物の入口)
 */
final class WritesRecords
{
    public function __construct(private CreateRecord $createRecord, private UpdateRecord $updateRecord) {}

    public function createRecord(): CreateRecord
    {
        return $this->createRecord;
    }

    public function updateRecord(): UpdateRecord
    {
        return $this->updateRecord;
    }
}
