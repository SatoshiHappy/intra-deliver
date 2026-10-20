<?php

namespace App\Actions\Master;

use App\Models\Master\UserTerm;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * ユーザーの期間を、ある日で分ける(docs の 設計/共通マスタ/共通の規則.md 5.5 の 4 行目)。退職(後ろの期間を無効にする)や氏名の変更の前に使う。
 *
 * 後ろの期間は前の期間のロケール別の行を写したものから始め、渡した値で上書きする(渡したロケールの行が無ければ作る)。性別は引き継ぐ。
 */
class SplitUserTerm
{
    use ResolvesLocales;
    use SplitsTerm;
    use WritesCommonMaster;

    /**
     * @param  array<string, array<string, string|null>>  $translations  後ろの期間で変える値(ロケールのコード → 列)
     * @return UserTerm 後ろの期間の行
     *
     * @throws ValidationException 分ける日が期間の中に無いとき、無いロケールのコードがあるとき
     */
    public function execute(int $userId, DateTimeInterface $date, array $translations = []): UserTerm
    {
        return $this->writeCommonMaster(function () use ($userId, $date, $translations) {
            $localeIds = $this->localeIds($translations);
            $later = $this->splitTerm(
                UserTerm::query()->where('user_id', $userId)->get(),
                $date,
                fn (UserTerm $term) => $term->userTermTranslations(),
            );

            foreach ($translations as $localeCode => $values) {
                $later->userTermTranslations()->updateOrCreate(['locale_id' => $localeIds[$localeCode]], $values);
            }

            return $later;
        });
    }
}
