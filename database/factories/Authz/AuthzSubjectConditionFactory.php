<?php

namespace Database\Factories\Authz;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\CompanyPost;
use App\Models\Master\Department;
use App\Models\Master\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuthzSubjectCondition>
 */
class AuthzSubjectConditionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'authz_subject_group_id' => AuthzSubjectGroup::factory(),
            'user_id' => User::factory(),
            'department_id' => null,
            'company_post_id' => null,
            'role_id' => null,
            'ipv4_pattern' => null,
            'meta_subject' => null,
            'term_start_date' => null,
            'term_end_date' => null,
            'child_authz_subject_group_id' => null,
            'operator' => ConditionOperator::Equal,
            'is_negated' => false,
        ];
    }

    /**
     * 相手を組織にする(ユーザーを外す)。
     */
    public function forDepartment(): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'department_id' => Department::factory()]);
    }

    /**
     * 相手を役職にする(ユーザーを外す)。
     */
    public function forCompanyPost(): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'company_post_id' => CompanyPost::factory()]);
    }

    /**
     * 相手をロールにする(ユーザーを外す)。
     */
    public function forRole(): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'role_id' => Role::factory()]);
    }

    /**
     * 相手を IPv4 アドレスのパターンにする(ユーザーを外す)。
     */
    public function forIpv4Pattern(string $pattern = '192.168.0.*'): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'ipv4_pattern' => $pattern]);
    }

    /**
     * 相手を認証状態にする(ユーザーを外す)。
     */
    public function forMetaSubject(MetaSubject $metaSubject = MetaSubject::Authenticated): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'meta_subject' => $metaSubject]);
    }

    /**
     * 相手を期間 [開始日, 終了日) にする(ユーザーを外す)。
     */
    public function forTerm(string $startDate = '2026-01-01', string $endDate = '2027-01-01'): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'term_start_date' => $startDate, 'term_end_date' => $endDate]);
    }

    /**
     * 相手を子の対象者にする(ユーザーを外す)。
     */
    public function forChildSubjectGroup(): static
    {
        return $this->state(fn (): array => ['user_id' => null, 'child_authz_subject_group_id' => AuthzSubjectGroup::factory()]);
    }

    /**
     * 除外にする(相手に合うかを反転する)。
     */
    public function negated(): static
    {
        return $this->state(fn (): array => ['is_negated' => true]);
    }
}
