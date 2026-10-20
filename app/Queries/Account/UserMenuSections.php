<?php

namespace App\Queries\Account;

use App\Support\AccessContext;

/**
 * 利用者メニューに、ほかのモジュールが足す節(見出しと項目の並び)を返す口(依存の逆転 = docs の 設計/コード構成/README.md 3 節、
 * 設計/デモユーザー切替/今の利用者.md 5.7)。
 *
 * Account は後ろのモジュールに依存できないので、節を足すモジュールが自分の読み取りの層にこれを実装し、起動(App\Providers)が
 * タグ {@see self::TAG} で登録する。共有プロパティ userMenuSections は、タグで束ねた実装を登録の順に呼んだ節の一覧
 * (共有するのは Account のミドルウェア ShareCurrentAccount)。節の値はクラスにせず、下の形の配列で返す。
 * 項目の name は d・ロケールの名称で、無ければ null(画面がコードを出す = docs の 設計/デモユーザー切替/README.md の方針 9)。
 */
interface UserMenuSections
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'account.user-menu-sections';

    /**
     * 要求の判定の入力で作る節 1 つ。項目が無ければ items は空(画面は項目の無い節を出さない)。
     * 項目の code は節の中で一意(画面が項目の識別 = React の key に使う)。
     *
     * @return array{heading: string, items: list<array{code: string, name: string|null, note: string|null, url: string, method: 'get'|'post', data: array<string, int|string>}>}
     */
    public function section(AccessContext $context): array;
}
