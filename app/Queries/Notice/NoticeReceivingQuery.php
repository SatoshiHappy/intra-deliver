<?php

namespace App\Queries\Notice;

use App\Models\Notice\NoticeOptOut;
use App\Models\Notice\NoticeRequiredType;
use Illuminate\Database\Eloquent\Builder;

/**
 * 通知の受け取りの設定と必須の印の読み取り(docs の 設計/ワークフロー/申請の周辺/通知の受け取りの設定/種類と作る規則.md の 2 節、
 * ADR 0045 の決定 1・3)。種類が一覧にあるか・切り替えられるかは見ない(呼び手が {@see NoticeTypeCatalog} で確かめる)。
 * 必須の印の読み方と必須の当て方は 1 か所ずつにする(送る時と画面と書き込みの拒みで当て方が食い違わないように)。
 */
class NoticeReceivingQuery
{
    /**
     * 渡した利用者のうち、その種類を受け取らない人(必須の種類なら誰もいない。1 回の問い合わせで読む)。
     *
     * @param  list<int>  $userIds  重ねない
     * @return array<int, int> 値だけに意味がある(呼び手は集合として差を取る)
     */
    public function optedOutAmong(string $type, array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $optedOut = $this->effectiveOptOuts()->where('type', $type)->whereIn('user_id', $userIds)
            ->pluck('user_id')->all();

        return array_map('intval', $optedOut);
    }

    /**
     * その利用者が受け取らない種類のすべて(必須の種類を除く。利用者の画面の行の見え方 = 利用者と管理の画面.md の 3.2)。
     *
     * @return list<string>
     */
    public function optedOutTypesOf(int $userId): array
    {
        return array_values($this->effectiveOptOuts()->where('user_id', $userId)->pluck('type')->all());
    }

    /**
     * その種類に必須の印があるか。
     */
    public function isRequiredType(string $type): bool
    {
        return $this->requiredMarks()->where('type', $type)->exists();
    }

    /**
     * 必須の印のある種類のすべて(2 つの画面の必須の札と値 = 利用者と管理の画面.md の 3.2・4.2)。
     *
     * @return list<string>
     */
    public function requiredTypes(): array
    {
        return array_values($this->requiredMarks()->pluck('type')->all());
    }

    /**
     * 効いている受け取らないの行(同じ種類に必須の印がある行を除く = 種類と作る規則.md の 2 節の表の 3 行目)。
     *
     * @return Builder<NoticeOptOut>
     */
    private function effectiveOptOuts(): Builder
    {
        return NoticeOptOut::query()->whereNotExists(
            $this->requiredMarks()->whereColumn(
                (new NoticeRequiredType)->qualifyColumn('type'),
                (new NoticeOptOut)->qualifyColumn('type'),
            ),
        );
    }

    /**
     * 必須の印の行を読む問い合わせ(必須の印に絞りの条件を足すときはここ。3 つの読み方が同じ条件を通る)。
     *
     * @return Builder<NoticeRequiredType>
     */
    private function requiredMarks(): Builder
    {
        return NoticeRequiredType::query();
    }
}
