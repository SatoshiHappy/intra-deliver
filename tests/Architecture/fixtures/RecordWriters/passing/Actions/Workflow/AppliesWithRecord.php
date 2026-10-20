<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\UpdateRecord;

/**
 * 一覧の namespace(ワークフローの申請・再申請)
 */
final class AppliesWithRecord
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
