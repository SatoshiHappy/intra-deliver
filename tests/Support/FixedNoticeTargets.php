<?php

namespace Tests\Support;

use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\Api\NoticeTargets;
use App\Support\AccessContext;

/**
 * テストの中だけで使う通知の移り先の実装(docs の 設計/ワークフロー/案件の運用/通知.md の 5 節の口): 決めた対象の種類に答え、
 * 決めた対象の id だけを見える対象にする。resolve の呼び出し(対象の種類と id)を覚える(1 描画で何回判定するかを確かめるため)。
 */
final class FixedNoticeTargets implements NoticeTargets
{
    /** @var list<array{string, list<int>}> */
    public array $calls = [];

    /**
     * @param  list<string>  $targetTypes
     * @param  array<int, NoticeTargetLink>  $links  見える対象の id → 移り先
     */
    public function __construct(
        private readonly array $targetTypes,
        private readonly array $links,
        private readonly string $unavailableText,
    ) {}

    public function targetTypes(): array
    {
        return $this->targetTypes;
    }

    public function resolve(string $targetType, array $targetIds, AccessContext $context): array
    {
        $this->calls[] = [$targetType, $targetIds];

        return array_intersect_key($this->links, array_flip($targetIds));
    }

    public function unavailableText(string $targetType): string
    {
        return $this->unavailableText;
    }
}
