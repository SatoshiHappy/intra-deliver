<?php

namespace App\Queries\Workflow;

use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Queries\Authz\Api\SelectionSubjects;
use App\Queries\Master\Api\MasterNames;
use DateTimeInterface;

/**
 * 処理対象者・参照者・確認する人の対象者の行(docs の 設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 3 節の SubjectRow)と、
 * 図の確認の札の名前(設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.6 節)を、認可の公開の口
 * {@see SelectionSubjects::conditionsOf()} の結果から作る。名前の無い相手は「#{id}」(認可設定の画面の出し方と同じ)。
 * ユーザーの行の所属は共通マスタの公開の口 {@see MasterNames::mainDepartmentNames()} で引く(名前だけの札は引かない)。
 *
 * @phpstan-import-type SubjectRow from StepTarget
 * @phpstan-import-type SelectionConditionView from SelectionSubjects
 */
class SubjectRows
{
    public function __construct(private SelectionSubjects $subjectGroups, private MasterNames $names) {}

    /**
     * @param  list<int>  $subjectGroupIds
     * @return array<int, list<SubjectRow>> 対象者の id → 行(条件の作った順。用途 selection でない・無い対象者は入らない)
     */
    public function of(array $subjectGroupIds, DateTimeInterface $date, string $localeCode): array
    {
        $groups = $this->groupsOf($subjectGroupIds, $date, $localeCode);
        $userIds = $this->userIdsOf($groups);
        $affiliations = $userIds === []
            ? [] : $this->names->mainDepartmentNames($userIds, $date, $localeCode);

        return array_map(fn (array $group) => array_map(fn (array $condition) => [
            'type' => $condition['kind']->value,
            'id' => $condition['id'],
            'name' => self::nameOf($condition),
            'affiliation' => $condition['kind'] === SelectionConditionKind::User
                ? ($affiliations[$condition['id']] ?? null) : null,
        ], $group['conditions']), $groups);
    }

    /**
     * 対象者の id → 相手の名前の並び(条件の作った順。図の確認の札 = 設計/ワークフロー/案件の運用/画面/管理と通知の画面.md 7.6 節)。
     *
     * @param  list<int|null>  $subjectGroupIds  null は除く
     * @return array<int, list<string>>
     */
    public function namesOf(array $subjectGroupIds, DateTimeInterface $date, string $localeCode): array
    {
        $ids = array_values(array_unique(array_filter($subjectGroupIds, fn (?int $id) => $id !== null)));

        return array_map(
            fn (array $group) => array_map(self::nameOf(...), $group['conditions']),
            $this->groupsOf($ids, $date, $localeCode),
        );
    }

    /**
     * @param  list<int>  $subjectGroupIds
     * @return array<int, array{combination: SubjectCombination, conditions: list<SelectionConditionView>}>
     */
    private function groupsOf(array $subjectGroupIds, DateTimeInterface $date, string $localeCode): array
    {
        return $subjectGroupIds === []
            ? [] : $this->subjectGroups->conditionsOf($subjectGroupIds, $date, $localeCode);
    }

    /**
     * @param  SelectionConditionView  $condition
     */
    private static function nameOf(array $condition): string
    {
        return $condition['name'] ?? "#{$condition['id']}";
    }

    /**
     * @param  array<int, array{combination: SubjectCombination, conditions: list<SelectionConditionView>}>  $groups
     * @return list<int> 条件に出るユーザーの id(重ねない)
     */
    private function userIdsOf(array $groups): array
    {
        $userIds = [];

        foreach ($groups as $group) {
            foreach ($group['conditions'] as $condition) {
                if ($condition['kind'] === SelectionConditionKind::User) {
                    $userIds[$condition['id']] = $condition['id'];
                }
            }
        }

        return array_values($userIds);
    }
}
