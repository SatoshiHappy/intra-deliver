<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\AuthorizationQuery;
use DateTimeInterface;

/**
 * 業務モジュールのリソースで、アクションを許されるか(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2.1 の業務モジュールの判定)。
 * 判定の入口 {@see RecordModuleGate} と、行の判定 {@see RecordRowAccess}(全件の参照を見る)の両方が使う。
 * 入口が行の判定に依存するので、互いに依存しないよう分けた。
 */
class RecordModuleAuthorization
{
    public function __construct(private AuthorizationQuery $authorization) {}

    /**
     * 判定を求める者が d に、業務モジュールのアクションを許されるか(リソースが無ければ禁止)。
     */
    public function allows(
        Requester $requester,
        RecordModule $module,
        RecordModuleAction $action,
        DateTimeInterface $date,
    ): bool {
        $uri = RecordModuleAction::uri($module->code);

        return $this->authorization->allowsUri($requester, $uri, $action->value, $date);
    }
}
