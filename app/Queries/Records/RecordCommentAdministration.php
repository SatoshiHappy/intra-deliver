<?php

namespace App\Queries\Records;

use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\RouteAuthorization;
use DateTimeInterface;

/**
 * コメントの管理の権限(docs の 設計/業務データの入れ物/レコードの共通機能/コメント.md の 4 節): 管理のルートの execute を許されるか
 * (ルートの認可と同じ判定。業務モジュールによらない)。欄の「ほかの人の分も削除できるか」の出し分けに使う(管理のルート自体は入口の認可が止める)。
 */
class RecordCommentAdministration
{
    /** ほかの人のコメントを消す管理のルート */
    public const ROUTE = 'records.admin.comments.destroy';

    public function __construct(private RouteAuthorization $authorization) {}

    public function allows(Requester $requester, DateTimeInterface $date): bool
    {
        return $this->authorization->allows($requester, self::ROUTE, $date);
    }
}
