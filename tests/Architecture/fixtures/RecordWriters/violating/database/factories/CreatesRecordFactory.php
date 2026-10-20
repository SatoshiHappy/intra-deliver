<?php

namespace Database\Factories\RecordWritersFixture;

use App\Actions\Records\Api\CreateRecord;

/**
 * database/ の下の seeder でないファイル(factory)は一覧の外
 */
final class CreatesRecordFactory
{
    public function __construct(private CreateRecord $createRecord) {}

    public function createRecord(): CreateRecord
    {
        return $this->createRecord;
    }
}
