<?php

namespace App\Queries\Authz\Api;

use InvalidArgumentException;

/**
 * 守るルート 1 つ(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 4 節): ルートの名前と、そのルートを実行できなければならない判定を求める者。
 * 判定を求める者は guest(認証されていない判定を求める者)だけ(今の用途がそれだけなので。値を足すのは用途ができたとき)。
 *
 * Authz の公開の口({@see GuardedRoutes} が返す値)。
 */
final readonly class GuardedRoute
{
    /** 認証されていない判定を求める者 */
    public const GUEST = 'guest';

    /**
     * @param  string  $routeName  ルートの名前
     * @param  string  $requester  判定を求める者({@see self::GUEST} だけ)
     *
     * @throws InvalidArgumentException 判定を求める者が guest でないとき(呼び手の誤り)
     */
    public function __construct(
        public string $routeName,
        public string $requester,
    ) {
        if ($requester !== self::GUEST) {
            throw new InvalidArgumentException("守るルートの判定を求める者は guest だけ: {$requester}");
        }
    }
}
