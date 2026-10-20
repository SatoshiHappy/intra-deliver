<?php

namespace App\Queries\Notice\Api;

use App\Queries\Notice\NoticeTargetCatalog;
use App\Support\AccessContext;

/**
 * 通知の対象の移り先を答える口(依存の逆転 = docs の 設計/コード構成/README.md の 3 節。保証の正本 = 設計/ワークフロー/案件の運用/通知.md の 1 節と
 * 5 節)。対象の種類を持つ後ろのモジュールが実装し、起動(App\Providers)がタグ {@see self::TAG} で登録する。通知のモジュールは実装を直接呼ばず、
 * 束ねる 1 か所(内部の {@see NoticeTargetCatalog})を通す。
 */
interface NoticeTargets
{
    /** 実装を束ねるコンテナのタグ */
    public const TAG = 'notice.targets';

    /**
     * この実装が答える対象の種類(例 workflow-case)。
     *
     * @return list<string>
     */
    public function targetTypes(): array;

    /**
     * 対象の集合を 1 回で判定し、判定を求める者(今の利用者・今日)に見える対象の移り先を返す(行ごとに問い合わせない)。
     *
     * @param  string  $targetType  {@see self::targetTypes()} のどれか
     * @param  list<int>  $targetIds  対象の id(重ねない)
     * @return array<int, NoticeTargetLink> 見える対象の id → 移り先(見えない・無い対象は入れない)
     */
    public function resolve(string $targetType, array $targetIds, AccessContext $context): array;

    /**
     * 対象が見えないときの 1 行(例 案件 = 「この申請は閲覧できません。」)。
     */
    public function unavailableText(string $targetType): string;
}
