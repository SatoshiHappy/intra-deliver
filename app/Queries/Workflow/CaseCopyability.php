<?php

namespace App\Queries\Workflow;

use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\RouteAuthorization;
use App\Support\AccessContext;

/**
 * 申請の詳細のボタン「複製して申請」を出すか(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の
 * 12.2 節)。押すと 403 になるボタンを出さないため、移り先の GET と同じ口で判定する。
 */
class CaseCopyability
{
    /** 移り先のルート(新規の申請の画面にクエリ copy) */
    private const ROUTE = 'workflow.applications.create';

    public function __construct(
        private RouteAuthorization $routes,
        private ApplicableFlows $applicableFlows,
    ) {}

    public function canCopy(AccessContext $context, int $flowId): bool
    {
        $requester = Requester::fromAccessContext($context);

        return $this->routes->allows($requester, self::ROUTE, $context->date)
            && $this->applicableFlows->isApplicableByAny($requester, $flowId, $context->date);
    }
}
