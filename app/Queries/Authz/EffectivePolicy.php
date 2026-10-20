<?php

namespace App\Queries\Authz;

use App\Enums\Authz\PolicyEffect;

/**
 * 判定に効いている設定 1 つ(docs の 設計/共通マスタ/ロールと認可/認可の確認/README.md 3.1、設計/共通マスタ/ロールと認可/認可の確認/認可設定の画面.md 2.2): 判定するリソースグループから親へたどって、
 * 同じリソースタイプとアクションのポリシーのうち、対象者ごとに最初に見つかったもの。
 */
final readonly class EffectivePolicy
{
    /**
     * @param  int  $resourceGroupId  ポリシーを置いたリソースグループ(判定するリソースグループか、その祖先)
     * @param  bool  $matches  判定を求める者が d にこの対象者に合う
     */
    public function __construct(
        public int $policyId,
        public int $subjectGroupId,
        public int $resourceGroupId,
        public PolicyEffect $effect,
        public bool $matches,
    ) {}
}
