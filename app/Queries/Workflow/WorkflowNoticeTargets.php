<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowProxy;
use App\Queries\Authz\Api\Requester;
use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\Api\NoticeTargets;
use App\Support\AccessContext;
use InvalidArgumentException;

/**
 * ワークフローの通知の対象の移り先(docs の 設計/ワークフロー/案件の運用/通知.md の 5 節)。案件は閲覧の判定({@see CaseVisibility})に
 * 1 回通し、見える案件だけを詳細へ移す(連絡は閲覧の権限を与えない)。代理は、行がまだあり判定を求める者がその代理先なら代理の設定へ移す。
 * どちらも対象の表示名を出さない。起動がタグ {@see NoticeTargets::TAG} で登録する。
 */
class WorkflowNoticeTargets implements NoticeTargets
{
    /** 対象の種類: 案件 */
    public const CASE_TARGET_TYPE = 'workflow-case';

    /** 対象の種類: 代理(対象 = 代理の行の id) */
    public const PROXY_TARGET_TYPE = 'workflow-proxy';

    /** 案件が見えないときの 1 行 */
    public const CASE_UNAVAILABLE_TEXT = 'この申請は閲覧できません。';

    /** 代理が見えないときの 1 行 */
    public const PROXY_UNAVAILABLE_TEXT = 'この代理の設定は削除されました。';

    public function __construct(private CaseVisibility $visibility) {}

    public function targetTypes(): array
    {
        return [self::CASE_TARGET_TYPE, self::PROXY_TARGET_TYPE];
    }

    public function resolve(string $targetType, array $targetIds, AccessContext $context): array
    {
        return match ($targetType) {
            self::CASE_TARGET_TYPE => $this->caseLinks($targetIds, $context),
            self::PROXY_TARGET_TYPE => $this->proxyLinks($targetIds, $context),
            default => throw self::unknown($targetType),
        };
    }

    public function unavailableText(string $targetType): string
    {
        return match ($targetType) {
            self::CASE_TARGET_TYPE => self::CASE_UNAVAILABLE_TEXT,
            self::PROXY_TARGET_TYPE => self::PROXY_UNAVAILABLE_TEXT,
            default => throw self::unknown($targetType),
        };
    }

    /**
     * @param  list<int>  $caseIds
     * @return array<int, NoticeTargetLink>
     */
    private function caseLinks(array $caseIds, AccessContext $context): array
    {
        $query = WorkflowCase::query()->whereIn('id', $caseIds);
        $requester = Requester::fromAccessContext($context);
        $visibleIds = $this->visibility->visible($query, $requester, $context->date)->pluck('id')->all();
        $links = [];

        foreach ($visibleIds as $caseId) {
            $links[(int) $caseId] = new NoticeTargetLink(route('workflow.cases.show', ['case' => (int) $caseId]), null);
        }

        return $links;
    }

    /**
     * 代理の通知の宛先は代理先だけなので、今の利用者が代理先の行だけを見える行にする(ゲストは無い)。
     *
     * @param  list<int>  $proxyIds
     * @return array<int, NoticeTargetLink>
     */
    private function proxyLinks(array $proxyIds, AccessContext $context): array
    {
        if ($context->userId === null) {
            return [];
        }

        $visibleIds = WorkflowProxy::query()->whereIn('id', $proxyIds)->where('proxy_user_id', $context->userId)
            ->pluck('id')->all();
        $links = [];

        foreach ($visibleIds as $proxyId) {
            $links[(int) $proxyId] = new NoticeTargetLink(route('workflow.proxies.index'), null);
        }

        return $links;
    }

    /**
     * 答えない対象の種類(束ねる所の誤り)。
     */
    private static function unknown(string $targetType): InvalidArgumentException
    {
        return new InvalidArgumentException("ワークフローは対象の種類 {$targetType} に答えない");
    }
}
