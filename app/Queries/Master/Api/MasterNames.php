<?php

namespace App\Queries\Master\Api;

use App\Models\Master\CompanyPost;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\Department;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use App\Queries\Master\MainDepartmentQuery;
use App\Queries\Master\TermQuery;
use DateTimeInterface;

/**
 * ユーザー・組織・役職の表示名(コードと d・ロケールの名前)。判定しない(表示名を返すだけ)。鍵を取らない。
 * 名前の読み方は {@see TermQuery}(d に有効な期間の行のロケール別の行。docs の 設計/共通マスタ/共通の規則.md 5.4)。
 *
 * Master の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1 の表)。
 * 存在しない id は結果に入れない。d に有効な期間の行・そのロケールの行が無ければ name は null(code はアンカーの値)。
 * ユーザーの主所属の名前は検索の候補と同じ規則({@see MainDepartmentQuery})。
 */
class MasterNames
{
    public function __construct(
        private TermQuery $terms,
        private MainDepartmentQuery $mainDepartments,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @param  string  $localeCode  ロケールのコード(呼び手が渡す)
     * @return array<int, array{code: string, name: string|null}> ユーザーの id → コードと名前(渡した順。重ねない)
     */
    public function users(array $userIds, DateTimeInterface $date, string $localeCode): array
    {
        $codes = User::query()->whereKey($userIds)->pluck('code', 'id')->all();
        $names = $this->terms->userTermsOn(array_keys($codes), $date, $localeCode)
            ->map(fn (UserTerm $term) => $term->userTermTranslations->first()?->name)
            ->all();

        return $this->named($userIds, $codes, $names);
    }

    /**
     * @param  list<int>  $departmentIds
     * @param  string  $localeCode  ロケールのコード(呼び手が渡す)
     * @return array<int, array{code: string, name: string|null}> 組織の id → コードと名前(渡した順。重ねない)
     */
    public function departments(array $departmentIds, DateTimeInterface $date, string $localeCode): array
    {
        $codes = Department::query()->whereKey($departmentIds)->pluck('code', 'id')->all();
        $names = $this->terms->departmentTermsOn(array_keys($codes), $date, $localeCode)
            ->map(fn (DepartmentTerm $term) => $term->departmentTermTranslations->first()?->name)
            ->all();

        return $this->named($departmentIds, $codes, $names);
    }

    /**
     * @param  list<int>  $companyPostIds
     * @param  string  $localeCode  ロケールのコード(呼び手が渡す)
     * @return array<int, array{code: string, name: string|null}> 役職の id → コードと名前(渡した順。重ねない)
     */
    public function companyPosts(array $companyPostIds, DateTimeInterface $date, string $localeCode): array
    {
        $codes = CompanyPost::query()->whereKey($companyPostIds)->pluck('code', 'id')->all();
        $names = $this->terms->companyPostTermsOn(array_keys($codes), $date, $localeCode)
            ->map(fn (CompanyPostTerm $term) => $term->companyPostTermTranslations->first()?->name)
            ->all();

        return $this->named($companyPostIds, $codes, $names);
    }

    /**
     * @param  list<int>  $userIds
     * @param  string  $localeCode  ロケールのコード(呼び手が渡す)
     * @return array<int, string|null> ユーザーの id → 主所属の組織の名前(主所属が無い・名前が無ければ null。渡した順。重ねない)
     */
    public function mainDepartmentNames(array $userIds, DateTimeInterface $date, string $localeCode): array
    {
        $existing = User::query()->whereKey($userIds)->pluck('id', 'id')->all();
        $mainDepartments = $this->mainDepartments->mainDepartmentsOn(array_keys($existing), $date, $localeCode);
        $names = [];

        foreach ($userIds as $id) {
            if (isset($existing[$id])) {
                $names[$id] = $mainDepartments[$id]['name'] ?? null;
            }
        }

        return $names;
    }

    /**
     * @param  list<int>  $ids
     * @param  array<int, string>  $codes  在る id → コード
     * @param  array<int, string|null>  $names  名前のある id → 名前
     * @return array<int, array{code: string, name: string|null}>
     */
    private function named(array $ids, array $codes, array $names): array
    {
        $named = [];

        foreach ($ids as $id) {
            if (isset($codes[$id])) {
                $named[$id] = ['code' => $codes[$id], 'name' => $names[$id] ?? null];
            }
        }

        return $named;
    }
}
