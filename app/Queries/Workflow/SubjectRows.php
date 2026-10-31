<?php

namespace App\Queries\Workflow;

use App\Queries\Authz\Api\SelectionSubjects;
use DateTimeInterface;

/**
 * 処理対象者・参照者の対象者の行(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 3 節の SubjectRow)を、
 * 認可の公開の口 {@see SelectionSubjects::conditionsOf()} の結果から作る。名前の無い相手は「#{id}」(認可設定の画面の出し方と同じ)。
 *
 * @phpstan-import-type SubjectRow from StepTarget
 */
class SubjectRows
{
    public function __construct(private SelectionSubjects $subjectGroups) {}

    /**
     * @param  list<int>  $subjectGroupIds
     * @return array<int, list<SubjectRow>> 対象者の id → 行(条件の作った順。用途 selection でない・無い対象者は入らない)
     */
    public function of(array $subjectGroupIds, DateTimeInterface $date, string $localeCode): array
    {
        $groups = $subjectGroupIds === []
            ? [] : $this->subjectGroups->conditionsOf($subjectGroupIds, $date, $localeCode);

        return array_map(fn (array $group) => array_map(fn (array $condition) => [
            'type' => $condition['kind']->value,
            'id' => $condition['id'],
            'name' => $condition['name'] ?? "#{$condition['id']}",
        ], $group['conditions']), $groups);
    }
}
