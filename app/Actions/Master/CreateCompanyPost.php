<?php

namespace App\Actions\Master;

use App\Models\Master\CompanyPost;
use App\Models\Master\CompanyPostTerm;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * 役職を作る(docs の 設計/共通マスタ/組織とユーザー.md 6 節)。最初の期間の行は [開始日, 上限なし)(共通の規則 5.3)。
 * ランクは期間に依らないのでアンカーに置く。ロケール別の行は渡されたロケールごとに作る。
 */
class CreateCompanyPost
{
    use ResolvesLocales;
    use WritesCommonMaster;

    /**
     * @param  array<string, array<string, string|null>>  $translations  ロケールのコード → 列(name は必須。ほかは notes)
     *
     * @throws ValidationException 無いロケールのコードがあるとき
     */
    public function execute(int $departmentSetId, string $code, int $rank, DateTimeInterface $startDate, array $translations, int $sortKey = 0): CompanyPost
    {
        $localeIds = $this->localeIds($translations);

        return $this->writeCommonMaster(function () use ($departmentSetId, $code, $rank, $startDate, $translations, $sortKey, $localeIds) {
            $companyPost = CompanyPost::query()->create([
                'department_set_id' => $departmentSetId,
                'code' => $code,
                'rank' => $rank,
                'sort_key' => $sortKey,
            ]);
            $term = CompanyPostTerm::query()->create(['company_post_id' => $companyPost->id, 'start_date' => $startDate->format('Y-m-d'), 'end_date' => null, 'is_deleted' => false]);

            foreach ($translations as $localeCode => $values) {
                $term->companyPostTermTranslations()->create([...$values, 'locale_id' => $localeIds[$localeCode]]);
            }

            return $companyPost;
        });
    }
}
