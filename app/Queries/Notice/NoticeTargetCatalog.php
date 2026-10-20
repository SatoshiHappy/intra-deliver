<?php

namespace App\Queries\Notice;

use App\Queries\Notice\Api\NoticeTargetLink;
use App\Queries\Notice\Api\NoticeTargets;
use App\Support\AccessContext;
use Illuminate\Container\Attributes\Tag;
use LogicException;

/**
 * 通知の対象の移り先の全体 = タグ {@see NoticeTargets::TAG} で束ねた実装を対象の種類で引く 1 か所(内部。docs の
 * 設計/ワークフロー/案件の運用/通知.md の 1 節と 5 節)。対象の種類ごとに実装の resolve を 1 回だけ呼ぶ。
 *
 * 同じ対象の種類を 2 つの実装が答えたら例外にする(片方で黙って上書きしない)。答える実装の無い対象の種類は例外にせず、共通の 1 行にして
 * 1 回の解決につき 1 回だけ記録する(保持した行が鈴の全ページを止めないため)。
 */
class NoticeTargetCatalog
{
    /** 答える実装の無い対象の種類の行に出す 1 行(通知.md の 5 節) */
    public const UNAVAILABLE_TEXT = 'この通知の対象は表示できません。';

    /**
     * @param  iterable<NoticeTargets>  $noticeTargets
     */
    public function __construct(#[Tag(NoticeTargets::TAG)] private iterable $noticeTargets) {}

    /**
     * 対象の種類ごとの id の集合を判定し、対象の種類 → 見える対象の移り先と見えないときの 1 行を返す。
     *
     * @param  array<string, list<int>>  $targetIdsByType  対象の種類 → 対象の id(重ねない)
     * @return array<string, array{links: array<int, NoticeTargetLink>, unavailableText: string}>
     *
     * @throws LogicException 同じ対象の種類を 2 つの実装が答えるとき
     */
    public function resolve(array $targetIdsByType, AccessContext $context): array
    {
        $implementations = $this->implementationsByType();
        $resolved = [];
        $missing = [];

        foreach ($targetIdsByType as $targetType => $targetIds) {
            $implementation = $implementations[$targetType] ?? null;

            if ($implementation === null) {
                $missing[] = "{$targetType}(".count($targetIds).' 件)';
                $resolved[$targetType] = ['links' => [], 'unavailableText' => self::UNAVAILABLE_TEXT];

                continue;
            }

            $resolved[$targetType] = [
                'links' => $implementation->resolve($targetType, $targetIds, $context),
                'unavailableText' => $implementation->unavailableText($targetType),
            ];
        }

        if ($missing !== []) {
            report(new LogicException('通知の対象の種類に移り先を答える実装が無い: '.implode('、', $missing)));
        }

        return $resolved;
    }

    /**
     * @return array<string, NoticeTargets>
     *
     * @throws LogicException
     */
    private function implementationsByType(): array
    {
        $implementations = [];

        foreach ($this->noticeTargets as $noticeTargets) {
            foreach ($noticeTargets->targetTypes() as $targetType) {
                if (array_key_exists($targetType, $implementations)) {
                    throw new LogicException("通知の対象の種類 {$targetType} を 2 つの実装が答えている(".$noticeTargets::class.')');
                }

                $implementations[$targetType] = $noticeTargets;
            }
        }

        return $implementations;
    }
}
