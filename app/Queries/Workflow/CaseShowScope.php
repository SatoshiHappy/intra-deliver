<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Support\AccessContext;
use Closure;

/**
 * 申請の詳細の 1 つの要求で組む props の材料({@see CaseShowProps} が作る)。今の値と版の写しを同じ応答の中で 2 回読まないため、
 * 求めた所で 1 回だけ読む関数で持つ。
 */
final readonly class CaseShowScope
{
    /**
     * @param  Closure(): array{module: array<string, mixed>, record: array<string, mixed>}  $current  申請書の今の値
     * @param  Closure(): array{values: array<string, mixed>}  $submitted  最後の申請・再申請の版の写し
     * @param  Closure(): int  $latestRevisionId  この申請の履歴の行が指す版のうち最大のもの
     */
    public function __construct(
        public AccessContext $context,
        public Requester $requester,
        public WorkflowCase $case,
        public int $recordId,
        public int $recordModuleId,
        public string $moduleCode,
        public CaseShowSelection $selection,
        public Closure $current,
        public Closure $submitted,
        public Closure $latestRevisionId,
    ) {}
}
