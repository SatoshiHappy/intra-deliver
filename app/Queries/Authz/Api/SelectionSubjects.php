<?php

namespace App\Queries\Authz\Api;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\SelectionConditionKind;
use App\Enums\Authz\SubjectCombination;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\RoleTranslation;
use App\Models\Master\CompanyPostTerm;
use App\Models\Master\DepartmentTerm;
use App\Models\Master\UserTerm;
use App\Queries\Master\TermQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Relation;
use LogicException;

/**
 * 用途 selection の専用の対象者(ワークフローの処理対象者・参照者の指定が指すもの = docs の 設計/共通マスタ/ロールと認可/対象者.md)の組み方と条件を、
 * 相手の d・ロケールの名前つきで読む。種類は書き込みの口(App\Actions\Authz\Api\CreateSelectionSubjectGroup)と同じ列挙 {@see SelectionConditionKind}
 * (読み取りの層は業務処理の層の値のクラスを使えないので、列挙と id で返す)。判定しない。鍵を取らない。
 *
 * Authz の公開の口(主体・認可・検証の範囲・ロック・原子性・結果・失敗の正本 = docs の 設計/ワークフロー/ルートの深さ/段2の設計への反映.md 3 節の表)。
 *
 * @phpstan-type SelectionConditionView array{kind: SelectionConditionKind, id: int, comparison: ConditionOperator, name: string|null}
 */
class SelectionSubjects
{
    public function __construct(private TermQuery $terms) {}

    /**
     * 対象者ごとの組み方と条件(条件は作った順 = id の順)。用途 selection でない・無い id は結果に入れない。条件 0 件の対象者は conditions が空。
     * 名前 = 相手の d・ロケールの名前(ユーザー・組織・役職は d に有効な期間の行のロケール別の行、ロールはそのロケールの表示名。無ければ null)。
     *
     * @param  list<int>  $subjectGroupIds
     * @return array<int, array{combination: SubjectCombination, conditions: list<SelectionConditionView>}> 対象者の id → 組み方と条件(渡した順。重ねない)
     *
     * @throws LogicException 用途 selection の対象者に、書き込みの口が作らない条件(4 種類の相手のほか・除外)があるとき(起きない)
     */
    public function conditionsOf(array $subjectGroupIds, DateTimeInterface $date, string $localeCode): array
    {
        $groups = AuthzSubjectGroup::query()
            ->whereKey($subjectGroupIds)
            ->where('purpose', SubjectGroupPurpose::Selection)
            ->with('authzSubjectConditions', fn (Relation $conditions) => $conditions->orderBy('id'))
            ->get()
            ->keyBy(fn (AuthzSubjectGroup $group) => $group->id);
        $conditions = $groups->flatMap(fn (AuthzSubjectGroup $group) => $group->authzSubjectConditions)->map(fn (AuthzSubjectCondition $condition) => $this->targetOf($condition));
        $names = $this->namesOf(array_values($conditions->all()), $date, $localeCode);
        $result = [];

        foreach ($subjectGroupIds as $subjectGroupId) {
            $group = $groups->get($subjectGroupId);

            if ($group !== null) {
                $result[$subjectGroupId] = ['combination' => $group->combination, 'conditions' => array_values($group->authzSubjectConditions->map(function (AuthzSubjectCondition $condition) use ($names) {
                    [$kind, $id] = $this->targetOf($condition);

                    return ['kind' => $kind, 'id' => $id, 'comparison' => $condition->operator, 'name' => $names[$kind->value][$id] ?? null];
                })->all())];
            }
        }

        return $result;
    }

    /**
     * 条件の相手の種類と id。
     *
     * @return array{SelectionConditionKind, int}
     *
     * @throws LogicException 4 種類の相手のほかの条件・除外の条件のとき
     */
    private function targetOf(AuthzSubjectCondition $condition): array
    {
        $target = match (true) {
            $condition->is_negated => null,
            $condition->user_id !== null => [SelectionConditionKind::User, $condition->user_id],
            $condition->department_id !== null => [SelectionConditionKind::Department, $condition->department_id],
            $condition->company_post_id !== null => [SelectionConditionKind::CompanyPost, $condition->company_post_id],
            $condition->role_id !== null => [SelectionConditionKind::Role, $condition->role_id],
            default => null,
        };

        return $target ?? throw new LogicException("用途 selection の対象者に、書き込みの口が作らない条件がある: {$condition->id}");
    }

    /**
     * 相手の名前(種類の値 → 相手の id → 名前。名前の無い相手は入らない)。
     *
     * @param  list<array{SelectionConditionKind, int}>  $targets
     * @return array<string, array<int, string|null>>
     */
    private function namesOf(array $targets, DateTimeInterface $date, string $localeCode): array
    {
        $ids = array_fill_keys(array_map(fn (SelectionConditionKind $kind) => $kind->value, SelectionConditionKind::cases()), []);

        foreach ($targets as [$kind, $id]) {
            $ids[$kind->value][] = $id;
        }

        return [
            SelectionConditionKind::User->value => $this->termNames($this->terms->userTermsOn($ids['user'], $date, $localeCode), fn (UserTerm $term) => $term->userTermTranslations->first()?->name),
            SelectionConditionKind::Department->value => $this->termNames($this->terms->departmentTermsOn($ids['department'], $date, $localeCode), fn (DepartmentTerm $term) => $term->departmentTermTranslations->first()?->name),
            SelectionConditionKind::CompanyPost->value => $this->termNames($this->terms->companyPostTermsOn($ids['company_post'], $date, $localeCode), fn (CompanyPostTerm $term) => $term->companyPostTermTranslations->first()?->name),
            SelectionConditionKind::Role->value => RoleTranslation::query()->whereIn('role_id', $ids['role'])->whereRelation('locale', 'code', $localeCode)->pluck('display_name', 'role_id')->all(),
        ];
    }

    /**
     * @template TTerm of UserTerm|DepartmentTerm|CompanyPostTerm
     *
     * @param  Collection<int, TTerm>  $terms  相手の id → d に有効な期間の行
     * @param  callable(TTerm): (string|null)  $name
     * @return array<int, string|null>
     */
    private function termNames(Collection $terms, callable $name): array
    {
        return $terms->map($name)->all();
    }
}
