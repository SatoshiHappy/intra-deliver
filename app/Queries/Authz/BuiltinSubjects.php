<?php

namespace App\Queries\Authz;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\SubjectGroupPurpose;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use Illuminate\Database\Eloquent\Builder;

/**
 * 組み込みの 3 列(docs の 設計/ワークフロー/申請の周辺/認可設定の編集.md 5.2): 初期データが作るシステム管理者・認証済みユーザ・ゲストの対象者。
 * id を定数にせず条件で引く(初期データを作り直しても同じ)。引き方は初期データの seeder と同じ: その条件 1 つ(除外でない・比較 eq)だけを持つ
 * 用途 policy の対象者のうち id の最も小さいもの(同じ条件の対象者が 2 つ以上あっても、組み込みは 1 つ)。
 */
class BuiltinSubjects
{
    /** システム管理者のロールのコード(初期データ) */
    public const SYSTEM_ADMINISTRATOR_ROLE_CODE = 'system_admin';

    /**
     * 組み込みの列の対象者の id(見つかったものだけ)。
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $roleId = Role::query()->where('code', self::SYSTEM_ADMINISTRATOR_ROLE_CODE)->value('id');
        $conditions = [
            ['meta_subject' => MetaSubject::Anonymous->value],
            ['meta_subject' => MetaSubject::Authenticated->value],
            ...($roleId === null ? [] : [['role_id' => $roleId]]),
        ];

        $ids = array_map(fn (array $condition) => $this->singleConditionSubjectId($condition), $conditions);

        return array_values(array_filter($ids, fn (?int $id) => $id !== null));
    }

    /**
     * その条件 1 つだけを持つ用途 policy の対象者のうち id の最も小さいもの。
     *
     * @param  array<string, int|string>  $subject  条件の相手の列と値
     */
    private function singleConditionSubjectId(array $subject): ?int
    {
        $attributes = [
            'user_id' => null,
            'department_id' => null,
            'company_post_id' => null,
            'role_id' => null,
            'ipv4_pattern' => null,
            'meta_subject' => null,
            'term_start_date' => null,
            'term_end_date' => null,
            'child_authz_subject_group_id' => null,
            ...$subject,
            'operator' => ConditionOperator::Equal->value,
            'is_negated' => false,
        ];

        $id = AuthzSubjectGroup::query()
            ->where('purpose', SubjectGroupPurpose::Policy)
            ->has('authzSubjectConditions', '=', 1)
            ->whereHas('authzSubjectConditions', fn (Builder $conditions) => $conditions->where($attributes))
            ->min('id');

        return $id === null ? null : (int) $id;
    }
}
