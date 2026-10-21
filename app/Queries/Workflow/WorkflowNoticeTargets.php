<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\Api\NoticeTargets;
use App\Support\AccessContext;
use InvalidArgumentException;

/**
 * ワークフローの通知の対象の移り先(docs の 設計/ワークフロー/案件の運用/通知.md の 5 節)。案件は閲覧の判定({@see CaseVisibility})に
 * 1 回通し、見える案件だけを詳細へ移す(連絡は閲覧の権限を与えない)。起動がタグ {@see NoticeTargets::TAG} で登録する。
 */
class WorkflowNoticeTargets implements NoticeTargets
{
    /** 対象の種類: 案件 */
    public const CASE_TARGET_TYPE = 'workflow-case';

    /** 案件が見えないときの 1 行 */
    public const CASE_UNAVAILABLE_TEXT = 'この申請は閲覧できません。';

    public function __construct(private CaseVisibility $visibility) {}

    public function targetTypes(): array
    {
        return [self::CASE_TARGET_TYPE];
    }

    public function resolve(string $targetType, array $targetIds, AccessContext $context): array
    {
        self::assertKnown($targetType);
        $query = WorkflowCase::query()->whereIn('id', $targetIds);
        $requester = Requester::fromAccessContext($context);
        $visibleIds = $this->visibility->visible($query, $requester, $context->date)->pluck('id')->all();
        $links = [];

        foreach ($visibleIds as $caseId) {
            $links[(int) $caseId] = new NoticeTargetLink(route('workflow.cases.show', ['case' => (int) $caseId]), null);
        }

        return $links;
    }

    public function unavailableText(string $targetType): string
    {
        self::assertKnown($targetType);

        return self::CASE_UNAVAILABLE_TEXT;
    }

    /**
     * @throws InvalidArgumentException 答えない対象の種類(束ねる所の誤り)
     */
    private static function assertKnown(string $targetType): void
    {
        if ($targetType !== self::CASE_TARGET_TYPE) {
            throw new InvalidArgumentException("ワークフローは対象の種類 {$targetType} に答えない");
        }
    }
}
