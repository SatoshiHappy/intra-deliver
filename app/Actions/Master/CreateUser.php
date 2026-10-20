<?php

namespace App\Actions\Master;

use App\Enums\Master\Sex;
use App\Models\Master\User;
use App\Models\Master\UserTerm;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;

/**
 * ユーザー(プロファイル)を作る(docs の 設計/共通マスタ/組織とユーザー.md 7 節)。最初の期間の行は [開始日, 上限なし)(共通の規則 5.3)。
 * 性別は国際化されない値なので期間の行に置く。ロケール別の行は渡されたロケールごとに作る。
 */
class CreateUser
{
    use ResolvesLocales;
    use WritesCommonMaster;

    /**
     * @param  array<string, array<string, string|null>>  $translations  ロケールのコード → 列(name は必須。ほかは user_term_translations の列)
     *
     * @throws ValidationException 無いロケールのコードがあるとき
     */
    public function execute(string $code, DateTimeInterface $startDate, array $translations, ?Sex $sex = null, int $sortKey = 0): User
    {
        $localeIds = $this->localeIds($translations);

        return $this->writeCommonMaster(function () use ($code, $startDate, $translations, $sex, $sortKey, $localeIds) {
            $user = User::query()->create(['code' => $code, 'sort_key' => $sortKey]);
            $term = UserTerm::query()->create(['user_id' => $user->id, 'start_date' => $startDate->format('Y-m-d'), 'end_date' => null, 'is_deleted' => false, 'sex' => $sex]);

            foreach ($translations as $localeCode => $values) {
                $term->userTermTranslations()->create([...$values, 'locale_id' => $localeIds[$localeCode]]);
            }

            return $user;
        });
    }
}
