<?php

namespace App\Queries\Workflow;

/**
 * 処理担当者の変更の変更後の人数の上限(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.3 節): 展開の行は 1 人、ほかは 20 人。
 * 業務処理の検証と詳細の申請操作の面が同じ値を使う。受けるのは親のノードの id(null = 展開の行でない)。
 */
final class RetargetUserLimit
{
    /** 展開の行でないノードの上限 */
    public const MAX_USERS = 20;

    /**
     * 展開の行か(変更後は 1 人だけ)。
     */
    public static function isChild(?int $parentNodeId): bool
    {
        return $parentNodeId !== null;
    }

    /**
     * 変更後の人数の上限。
     */
    public static function maxOf(?int $parentNodeId): int
    {
        return self::isChild($parentNodeId) ? 1 : self::MAX_USERS;
    }
}
