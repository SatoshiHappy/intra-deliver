<?php

namespace App\Queries\Authz\Api;

use App\Queries\Authz\AuthorizationMemo;
use Closure;

/**
 * 判定の使い回し(docs の 設計/共通マスタ/ロールと認可/認可の確認/判定の使い回し.md 3.4。理由 = ADR 0046): {@see self::within()} の中では、
 * URI を渡す判定のすべて({@see Authorization::allowsUri()}・{@see RouteAuthorization::allows()} と、Authz の中の URI の判定を通るもの)が、
 * 同じ判定を求める者・URI・アクション・基準日の組に、覚えを捨てるまで最初の結果を返す。
 * 囲むのは書き込みも鍵も取らない読み取りの組み立てだけ。判定し直す所は {@see self::without()} で囲む(外のまとまりの中でも使い回さない)。
 *
 * Authz の公開の口(保証の正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口/付け替えと土台の口.md 8.1 の表。規則の細目 = 3.4)。鍵を取らない。
 */
class AuthorizationReuse
{
    public function __construct(private AuthorizationMemo $memo) {}

    /**
     * まとまりの中で読み取りを行う(入れ子は外のまとまりに含まれる)。読み取りの結果と例外はそのまま返す。
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $read
     * @return TResult
     */
    public function within(Closure $read): mixed
    {
        $this->memo->open();

        try {
            return $read();
        } finally {
            $this->memo->close();
        }
    }

    /**
     * 使い回さない区間の中で判定する(外のまとまりの中から呼んでも、中では覚えを使わず覚えもしない。中で開いたまとまりも使い回さない。
     * 入る時に外のまとまりの覚えを捨てるので、区間を出た後の判定も区間の前の結果を返さない)。
     * 結果と例外はそのまま返す。
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $decide
     * @return TResult
     */
    public function without(Closure $decide): mixed
    {
        $this->memo->openBypass();

        try {
            return $decide();
        } finally {
            $this->memo->closeBypass();
        }
    }
}
