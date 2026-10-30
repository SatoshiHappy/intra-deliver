<?php

namespace Database\Seeders\RecordWritersFixture;

use App\Actions\Records\Api\HideRecordModuleUnchecked;
use Illuminate\Database\Seeder;

/**
 * 登録・更新の一覧の path(初期データ)も、非表示の口の一覧の外(初期データは別の手順を持たない = ADR 0031 の決定 3)
 */
final class HidesModuleSeeder extends Seeder
{
    public function __construct(private HideRecordModuleUnchecked $hideModule) {}

    public function hideModule(): HideRecordModuleUnchecked
    {
        return $this->hideModule;
    }
}
