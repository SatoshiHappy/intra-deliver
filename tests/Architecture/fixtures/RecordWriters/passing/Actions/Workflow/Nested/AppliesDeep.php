<?php

namespace App\Actions\Workflow\Nested;

use App\Actions\Records\Api\CreateRecord;

/**
 * 一覧の名前空間の配下(その名前空間と配下が通る)
 */
final class AppliesDeep
{
    public function __construct(private CreateRecord $createRecord) {}

    public function createRecord(): CreateRecord
    {
        return $this->createRecord;
    }
}
