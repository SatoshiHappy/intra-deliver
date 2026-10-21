<?php

namespace App\Actions\Master;

use App\Models\Master\Department;
use App\Models\Master\DepartmentTerm;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * 組織を作る(docs の 設計/共通マスタ/組織とユーザー.md 4 節)。
 *
 * 期間化するマスタの最初の期間の行は [開始日, 上限なし)(共通の規則 5.3: 最初の行の開始日から上限なしまで続く)。
 * ロケール別の行は渡されたロケールごとに作る(どのロケールを必ず置くかは多言語の設計で決めるので、ここでは求めない)。略称が無いか null なら名称を写す(本家の画面の挙動 = 4 節の略称の写し)。
 * 頂点の組織は会社を作るとき(CreateCompany)に作る。組織セットの頂点が 1 つまでなのは DB が止める。
 */
class CreateDepartment
{
    use ResolvesLocales;
    use WritesCommonMaster;

    /**
     * @param  array<string, array<string, string|null>>  $translations  ロケールのコード → 列(name は必須。ほかは department_term_translations の列)
     *
     * @throws ValidationException 無いロケールのコードがあるとき
     */
    public function execute(int $departmentSetId, string $code, DateTimeInterface $startDate, array $translations, int $sortKey = 0, bool $isRoot = false): Department
    {
        $localeIds = $this->localeIds($translations);

        return $this->writeCommonMaster(function () use ($departmentSetId, $code, $startDate, $translations, $sortKey, $isRoot, $localeIds) {
            $department = Department::query()->create([
                'department_set_id' => $departmentSetId,
                'code' => $code,
                'is_root' => $isRoot,
                'sort_key' => $sortKey,
            ]);
            $term = DepartmentTerm::query()->create(['department_id' => $department->id, 'start_date' => $startDate->format('Y-m-d'), 'end_date' => null, 'is_deleted' => false]);

            foreach ($translations as $localeCode => $values) {
                $term->departmentTermTranslations()->create([
                    ...$values,
                    'locale_id' => $localeIds[$localeCode],
                    'short_name' => $values['short_name'] ?? $values['name'] ?? null,
                ]);
            }

            return $department;
        });
    }
}
