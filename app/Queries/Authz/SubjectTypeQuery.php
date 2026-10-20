<?php

namespace App\Queries\Authz;

use App\Enums\Authz\SubjectType;
use App\Models\Authz\AuthzSubjectCondition;
use UnexpectedValueException;

/**
 * 対象者のカテゴリのコードを決めるサブジェクトタイプ(docs の 設計/共通マスタ/ロールと認可/対象者.md の authz_subject_categories):
 * その対象者の条件と、子の対象者をたどったすべての対象者の条件の種類。子の対象者を指す条件そのものは数えない。
 */
class SubjectTypeQuery
{
    /**
     * 対象者からたどれる対象者(自分を含む)の条件のサブジェクトタイプ(重ねない。並びは問わない)。子の対象者の循環でも終わるように、
     * たどったものを重ねない(UNION)。
     *
     * @return list<SubjectType>
     */
    public function subjectTypesOf(int $subjectGroupId): array
    {
        return array_values(AuthzSubjectCondition::query()
            ->whereNull('child_authz_subject_group_id')
            ->whereRaw(<<<'SQL'
                authz_subject_conditions.authz_subject_group_id IN (
                    WITH RECURSIVE reachable (id) AS (
                        SELECT ?::bigint
                        UNION
                        SELECT authz_subject_conditions.child_authz_subject_group_id FROM authz_subject_conditions
                        JOIN reachable ON authz_subject_conditions.authz_subject_group_id = reachable.id
                        WHERE authz_subject_conditions.child_authz_subject_group_id IS NOT NULL
                    )
                    SELECT reachable.id FROM reachable
                )
                SQL, [$subjectGroupId])
            ->get()
            ->map(fn (AuthzSubjectCondition $condition) => $this->subjectTypeOf($condition))
            ->unique(fn (SubjectType $subjectType) => $subjectType->value)
            ->all());
    }

    /**
     * 条件の相手のサブジェクトタイプ。条件は相手を 1 つだけ指す(検査制約。期間は開始日の列で数える)。
     *
     * @throws UnexpectedValueException どのサブジェクトの列にも当たらない条件(種類を足して、ここを直し忘れたとき)
     */
    private function subjectTypeOf(AuthzSubjectCondition $condition): SubjectType
    {
        return match (true) {
            $condition->user_id !== null => SubjectType::User,
            $condition->department_id !== null => SubjectType::Department,
            $condition->company_post_id !== null => SubjectType::CompanyPost,
            $condition->role_id !== null => SubjectType::Role,
            $condition->ipv4_pattern !== null => SubjectType::Ipv4Address,
            $condition->meta_subject !== null => SubjectType::MetaSubject,
            $condition->term_start_date !== null => SubjectType::Term,
            default => throw new UnexpectedValueException("サブジェクトタイプの決まらない条件(id {$condition->id})"),
        };
    }
}
