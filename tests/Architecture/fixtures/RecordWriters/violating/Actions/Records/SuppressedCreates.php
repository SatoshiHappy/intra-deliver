<?php

namespace App\Actions\Records;

use App\Actions\Records\Api\CreateRecord;

/**
 * 抑制の注釈を付けても消せない(nonIgnorable)。入れ物の内部も一覧の外
 */
final class SuppressedCreates
{
    // @phpstan-ignore phpat.recordWriterDependencies
    public function createRecord(CreateRecord $createRecord): CreateRecord
    {
        return $createRecord;
    }
}
