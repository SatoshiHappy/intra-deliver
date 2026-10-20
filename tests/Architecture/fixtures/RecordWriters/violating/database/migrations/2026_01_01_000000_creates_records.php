<?php

use App\Actions\Records\Api\CreateRecord;
use Illuminate\Database\Migrations\Migration;

/**
 * 名前空間を持たない無名クラスのマイグレーションも一覧の外
 */
return new class extends Migration
{
    public function createRecord(CreateRecord $createRecord): CreateRecord
    {
        return $createRecord;
    }
};
