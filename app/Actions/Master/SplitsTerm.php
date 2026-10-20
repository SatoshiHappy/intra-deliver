<?php

namespace App\Actions\Master;

use App\Support\Period;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * 期間化するマスタの期間の行を、ある日で 2 つに分ける(docs の 設計/共通マスタ/共通の規則.md 5.5 の 4 行目)。
 *
 * 分ける日 d を含む期間の行 [s, e) を [s, d) と [d, e) にする。分けた両方は元の行の is_deleted と期間の行の値を引き継ぎ、
 * ロケール別の行は新しい期間(後ろ)へ写す(前の行の分だけを 1 回で読む。関係のプロパティで遅延読み込みしない)。有効な日は変わらないので、連続(5.3)も、関係の行のはみ出し(5.5)も変わらない。
 * 前の行の終了日を先に縮めてから後ろの行を作る(業務キーの排他制約は即時に検査されるため)。
 */
trait SplitsTerm
{
    /**
     * @template TTerm of Model
     * @template TTranslation of Model
     *
     * @param  Collection<int, TTerm>  $terms  アンカーの期間の行すべて
     * @param  \Closure(TTerm): HasMany<TTranslation, TTerm>  $translationsOf  期間の行のロケール別の行の関係
     * @return TTerm 後ろの期間の行
     *
     * @throws ValidationException d を含む期間の行が無いとき、d が期間の行の開始日のとき(長さ 0 の期間はできない)
     */
    private function splitTerm(Collection $terms, DateTimeInterface $date, \Closure $translationsOf): Model
    {
        $day = (new Period($date))->start;
        $term = $terms->first(fn (Model $candidate) => (new Period($candidate->getAttribute('start_date'), $candidate->getAttribute('end_date')))->includes($day))
            ?? throw ValidationException::withMessages(['date' => ['分割する日を含む期間がありません']]);

        if ($day->equalTo((new Period($term->getAttribute('start_date')))->start)) {
            throw ValidationException::withMessages(['date' => ['期間の開始日では分割できません']]);
        }

        $later = $term->replicate();
        $later->setAttribute('start_date', $day->toDateString());
        $term->setAttribute('end_date', $day->toDateString());
        $term->save();
        $later->save();
        $translationsOf($later)->saveMany($translationsOf($term)->get()->map(fn ($translation) => $translation->replicate()));

        return $later;
    }
}
