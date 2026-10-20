<?php

namespace App\Models\Master;

use App\Support\Period;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

/**
 * 期間の列(start_date・end_date・is_deleted)を持つ行の「基準日 d に有効」(docs の 設計/共通マスタ/共通の規則.md 5.4)。
 *
 * 有効の定義はこの trait の 1 か所だけに置く。期間化するマスタが d に有効なこと(アンカーの effectiveOn)と、
 * 関係が d に成り立つこと(関係の行の holdsOn)は、この定義を組み合わせて書く。
 * 使う表 = 期間の表 3 つ(組織・役職・ユーザー)と期間化する関係の表 3 つ(内包・所属・ロールの付与)。
 */
trait HasPeriod
{
    /**
     * d に有効な行だけにする: start_date <= d かつ(end_date が NULL か d < end_date)かつ is_deleted が false。
     * 半開区間 [start_date, end_date) なので、開始日を含み終了日を含まない。end_date が NULL なら上限なし。
     * d は日付として扱う(時刻を捨て、渡された日時の暦日を使う)。
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function effectiveOn(Builder $query, DateTimeInterface $date): void
    {
        $day = $date->format('Y-m-d');
        $endDate = $query->qualifyColumn('end_date');

        $query->where($query->qualifyColumn('start_date'), '<=', $day)
            ->where(fn (Builder $end) => $end->whereNull($endDate)->orWhere($endDate, '>', $day))
            ->where($query->qualifyColumn('is_deleted'), false);
    }

    /**
     * この行が有効な期間([start_date, end_date))。無効の行(is_deleted)は有効な日を持たないので null。
     * 業務処理の規則(共通の規則 5.5)は、行が有効な日をこの期間で数える(effectiveOn と同じ定義)。
     */
    public function effectivePeriod(): ?Period
    {
        return $this->is_deleted ? null : new Period($this->start_date, $this->end_date);
    }
}
