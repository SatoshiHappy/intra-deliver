<?php

namespace App\Queries\Master;

use App\Support\Period;

/**
 * Master の外にある、ユーザーを指す期間つきの関係(期間に従う行)の有効な日を答える(依存の逆転 = docs の 設計/コード構成/README.md 3 節)。
 *
 * 「マスタの有効な日を減らすとき、それを指す期間に従う行の有効な日がはみ出さない」(docs の 設計/共通マスタ/共通の規則.md 5.5 の 3 行目)を、
 * Master の業務処理(ユーザーの有効な日を減らす)が、後ろのモジュールの表まで含めて確かめるのに使う。
 * Master は後ろのモジュールに依存できないので、後ろのモジュール(いまは Authz の user_roles)がこれを実装し、起動(App\Providers)が
 * タグ {@see self::TAG} で登録する。Master の業務処理は、タグで束ねた実装のすべてに問い合わせる。
 */
interface UserReferences
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'master.user-references';

    /**
     * ユーザーを指す行のうち、有効な行の有効な期間(行ごと。和にしない)。
     *
     * @return array<int, Period>
     */
    public function effectivePeriodsReferencing(int $userId): array;
}
