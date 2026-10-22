<?php

namespace App\Queries\Authz\Api;

use App\Queries\Authz\AuthorizationQuery;
use DateTimeInterface;

/**
 * 認可の判定(リソース URI とアクション)をほかのモジュールに使わせる口(docs の 設計/ワークフロー/案件の運用/README.md の「コードのモジュール」)。
 * 判定の意味は {@see AuthorizationQuery} が持ち、ここは内部を呼ぶだけ(意味を再実装しない)。鍵を取らない。
 *
 * Authz の公開の口。認可の確認の画面だけが使う判定(decide・explain)は内部のまま。
 */
class Authorization
{
    public function __construct(private AuthorizationQuery $authorization) {}

    /**
     * 判定を求める者が d に、リソース URI のリソースのアクションを許されるか。URI のリソースが無ければ禁止。
     */
    public function allowsUri(Requester $requester, string $uri, string $action, DateTimeInterface $d): bool
    {
        return $this->authorization->allowsUri($requester, $uri, $action, $d);
    }
}
