<?php

namespace App\Actions\Master;

use App\Models\Master\DepartmentTerm;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * 組織の期間を、ある日で分ける(docs の 設計/共通マスタ/共通の規則.md 5.5 の 4 行目)。名称などを日付で変える(名称の履歴)ときに使う。
 *
 * 後ろの期間は前の期間のロケール別の行を写したものから始め、渡した値で上書きする(渡したロケールの行が無ければ作る)。
 * 名称を渡して略称が無いか null なら名称を写し、書いた後の略称が null なら(略称だけ null を渡したときなど)その行の名称を写す(作るときと同じ = 組織とユーザー 4 節の略称の写し)。
 * 名称も略称も渡さず、写した略称が null でなければ、そのまま。
 */
class SplitDepartmentTerm
{
    use ResolvesLocales;
    use SplitsTerm;
    use WritesCommonMaster;

    /**
     * @param  array<string, array<string, string|null>>  $translations  後ろの期間で変える値(ロケールのコード → 列)
     * @return DepartmentTerm 後ろの期間の行
     *
     * @throws ValidationException 分ける日が期間の中に無いとき、無いロケールのコードがあるとき
     */
    public function execute(int $departmentId, DateTimeInterface $date, array $translations = []): DepartmentTerm
    {
        return $this->writeCommonMaster(function () use ($departmentId, $date, $translations) {
            $localeIds = $this->localeIds($translations);
            $later = $this->splitTerm(
                DepartmentTerm::query()->where('department_id', $departmentId)->get(),
                $date,
                fn (DepartmentTerm $term) => $term->departmentTermTranslations(),
            );

            foreach ($translations as $localeCode => $values) {
                $translation = $later->departmentTermTranslations()->updateOrCreate(
                    ['locale_id' => $localeIds[$localeCode]],
                    isset($values['name']) && ($values['short_name'] ?? null) === null ? [...$values, 'short_name' => $values['name']] : $values,
                );

                if ($translation->short_name === null) {
                    $translation->update(['short_name' => $translation->name]);
                }
            }

            return $later;
        });
    }
}
