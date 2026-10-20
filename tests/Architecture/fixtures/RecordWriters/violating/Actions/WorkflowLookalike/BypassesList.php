<?php

namespace App\Actions\WorkflowLookalike;

use App\Actions\Records\Api\CreateRecord;

/**
 * 一覧の名前空間と名前の頭が同じだけの別の名前空間は一覧の外
 */
final class BypassesList
{
    public function __construct(private CreateRecord $createRecord) {}

    public function createRecord(): CreateRecord
    {
        return $this->createRecord;
    }
}
