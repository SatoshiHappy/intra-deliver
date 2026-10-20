<?php

namespace Database\Seeders\RecordWritersFixture;

use App\Actions\Records\Api\CreateRecord;
use Illuminate\Database\Seeder;

/**
 * 一覧の path(初期データ)
 */
final class SeedsRecords extends Seeder
{
    public function __construct(private CreateRecord $createRecord) {}

    public function createRecord(): CreateRecord
    {
        return $this->createRecord;
    }
}
