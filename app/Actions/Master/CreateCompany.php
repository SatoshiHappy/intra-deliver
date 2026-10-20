<?php

namespace App\Actions\Master;

use App\Models\Master\Company;
use App\Models\Master\DepartmentSet;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * 会社を作る(docs の 設計/共通マスタ/組織とユーザー.md 2〜4 節)。既定の組織セットと、その頂点の組織を同時に作る。
 *
 * 止める規則: 会社がある間、その会社の既定の組織セットはちょうど 1 つある(3 節。本家も会社の作成時に既定の組織セットを作る)。
 * 組織セットがある間、頂点の組織はちょうど 1 つある(4 節)。会社と組織セットは名称を持たず、頂点の組織の名称が会社の名称になる。
 */
class CreateCompany
{
    use WritesCommonMaster;

    public function __construct(private CreateDepartment $createDepartment) {}

    /**
     * @param  array<string, array<string, string|null>>  $rootTranslations  頂点の組織(= 会社)のロケール別の列(CreateDepartment と同じ)
     *
     * @throws ValidationException 無いロケールのコードがあるとき
     */
    public function execute(string $code, string $departmentSetCode, string $rootDepartmentCode, DateTimeInterface $startDate, array $rootTranslations, int $sortKey = 0): Company
    {
        return $this->writeCommonMaster(function () use ($code, $departmentSetCode, $rootDepartmentCode, $startDate, $rootTranslations, $sortKey) {
            $company = Company::query()->create(['code' => $code, 'sort_key' => $sortKey]);
            $departmentSet = DepartmentSet::query()->create(['company_id' => $company->id, 'code' => $departmentSetCode, 'is_default' => true]);
            $this->createDepartment->execute($departmentSet->id, $rootDepartmentCode, $startDate, $rootTranslations, isRoot: true);

            return $company;
        });
    }
}
