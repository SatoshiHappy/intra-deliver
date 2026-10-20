<?php

namespace Database\Seeders;

use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use Carbon\CarbonImmutable;

/**
 * 初期データの seeder が、先に入れたマスタをコードで引く(初期データは会社 1 社・組織セット 1 個なので、コードで 1 つに決まる)。
 */
trait FindsCommonMaster
{
    /**
     * 基準の日に書いた日付を、流す日からの相対の日付にする({@see SeedingDates})。
     */
    private static function day(string $date): CarbonImmutable
    {
        return SeedingDates::date($date);
    }

    private function departmentSetId(): int
    {
        return DepartmentSet::query()->where('is_default', true)->sole()->id;
    }

    private function departmentId(string $code): int
    {
        return Department::query()->where('code', $code)->sole()->id;
    }

    private function companyPostId(string $code): int
    {
        return CompanyPost::query()->where('code', $code)->sole()->id;
    }

    private function userId(string $code): int
    {
        return User::query()->where('code', $code)->sole()->id;
    }

    private function roleId(string $code): int
    {
        return Role::query()->where('code', $code)->sole()->id;
    }
}
