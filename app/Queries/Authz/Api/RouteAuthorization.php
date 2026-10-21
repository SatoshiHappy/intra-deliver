<?php

namespace App\Queries\Authz\Api;

use App\Enums\Authz\ServiceAction;
use App\Queries\Authz\AuthorizationQuery;
use App\Queries\Authz\RouteResources;
use DateTimeInterface;

/**
 * ルートの認可の判定(docs の 設計/メニュー/認可との結び付け.md の 2.3 節。ルートのミドルウェア AuthorizeRoute と同じ判定):
 * 判定を求める者が d に、ルートの名前のリソース(URI の規則 = {@see RouteResources::uriOf()} の 1 か所)の execute を許されるか。
 * 画面の出し分け(業務モジュールによらない管理のルートの許可など)が、ルートの認可と同じ答えを得るために使う。
 *
 * Authz の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/業務データとの接続/公開の口.md 8.1 の表)。
 * 読み取りで鍵を取らない。例外を投げない(リソースの無いルートの名前は許されないとして偽)。
 */
class RouteAuthorization
{
    public function __construct(
        private AuthorizationQuery $authorization,
        private RouteResources $routeResources,
    ) {}

    public function allows(Requester $requester, string $routeName, DateTimeInterface $date): bool
    {
        return $this->authorization->allowsUri(
            $requester,
            $this->routeResources->uriOf($routeName),
            ServiceAction::Execute->value,
            $date,
        );
    }
}
