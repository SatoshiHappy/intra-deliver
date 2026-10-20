<?php

namespace App\Queries\Notice;

use App\Models\Notice\Notice;
use App\Queries\Master\Api\MasterNames;
use App\Queries\Notice\Api\NoticeTargetLink;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\Collection;

/**
 * 通知の行(鈴の 10 件・一覧の 20 件・押した時の 1 件)を画面に渡す形にする(docs の 設計/ワークフロー/案件の運用/通知.md の 5 節と 7 節)。
 * 行の対象を対象の種類ごとに集めて {@see NoticeTargetCatalog} に 1 回通し、起こした人の名前は今日・ロケールの名前で 1 回に引く。
 *
 * params は空でも JSON のオブジェクト {} で画面に渡すため stdClass にする(PHP の空の配列は JSON の配列 [] になる)。
 *
 * @phpstan-type NoticeRow array{id: int, type: string, params: \stdClass,
 *     actor: array{name: string|null, code: string}|null, createdAt: string, readAt: string|null,
 *     url: string|null, targetLabel: string|null, unavailableText: string|null}
 */
class NoticeRows
{
    public function __construct(
        private MasterNames $names,
        private NoticeTargetCatalog $targets,
    ) {}

    /**
     * @param  Collection<int, Notice>  $notices
     * @return list<NoticeRow> 渡した順
     */
    public function rows(Collection $notices, AccessContext $context): array
    {
        if ($notices->isEmpty()) {
            return [];
        }

        $actorIds = array_values(array_unique(array_filter($notices->pluck('actor_user_id')->all(), 'is_int')));
        $actors = $actorIds === [] ? [] : $this->names->users($actorIds, $context->date, $context->localeCode);
        $resolved = $this->targets->resolve($this->targetIdsByType($notices), $context);

        return array_values($notices->map(fn (Notice $notice): array => [
            'id' => $notice->id,
            'type' => $notice->type,
            'params' => (object) $notice->params,
            'actor' => self::actorOf($notice, $actors),
            'createdAt' => (string) $notice->created_at?->toIso8601String(),
            'readAt' => $notice->read_at?->toIso8601String(),
            ...self::destinationOf($notice, $resolved),
        ])->all());
    }

    /**
     * @param  Collection<int, Notice>  $notices
     * @return array<string, list<int>>
     */
    private function targetIdsByType(Collection $notices): array
    {
        $idsByType = [];

        foreach ($notices as $notice) {
            if ($notice->target_type !== null && $notice->target_id !== null) {
                $idsByType[$notice->target_type][$notice->target_id] = $notice->target_id;
            }
        }

        return array_map('array_values', $idsByType);
    }

    /**
     * @param  array<int, array{code: string, name: string|null}>  $actors
     * @return array{name: string|null, code: string}|null
     */
    private static function actorOf(Notice $notice, array $actors): ?array
    {
        $actor = $notice->actor_user_id === null ? null : ($actors[$notice->actor_user_id] ?? null);

        return $actor === null ? null : ['name' => $actor['name'], 'code' => $actor['code']];
    }

    /**
     * 行の移り先(通知.md の 5 節の 1 規則)。
     *
     * @param  array<string, array{links: array<int, NoticeTargetLink>, unavailableText: string}>  $resolved
     * @return array{url: string|null, targetLabel: string|null, unavailableText: string|null}
     */
    private static function destinationOf(Notice $notice, array $resolved): array
    {
        if ($notice->target_type === null || $notice->target_id === null) {
            return ['url' => null, 'targetLabel' => null, 'unavailableText' => null];
        }

        $link = $resolved[$notice->target_type]['links'][$notice->target_id] ?? null;

        if ($link !== null) {
            return ['url' => $link->url, 'targetLabel' => $link->label, 'unavailableText' => null];
        }

        $unavailableText = $resolved[$notice->target_type]['unavailableText'];

        return ['url' => null, 'targetLabel' => null, 'unavailableText' => $unavailableText];
    }
}
