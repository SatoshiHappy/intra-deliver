<?php

namespace Tests\Feature\Models\Authz;

use App\Enums\Authz\ConditionOperator;
use App\Enums\Authz\MetaSubject;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\Role;
use App\Models\Master\User;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AssertsConstraintViolations;
use Tests\Support\TransactionalTestCase;

/**
 * 認可の対象者の条件(docs の 設計/共通マスタ/ロールと認可/対象者.md: 1 つの条件は 1 つの相手だけを指す、比較は 5 つ(既定 eq)で、
 * eq 以外は組織と役職でだけ使う。IPv4 アドレスのパターンは書式、認証状態は 2 つの値、期間は両端そろって [開始日, 終了日)。
 * 子の対象者は自分自身でない)。
 * 「1 つの相手だけ」は置き換え前と後で切り詰めた名前が同じなので、置き換え後にだけ成り立つ振る舞い(足した種類 + ユーザーが止まる)で確かめる。
 */
final class AuthzSubjectConditionTest extends TransactionalTestCase
{
    use AssertsConstraintViolations;

    private const ONE_TARGET = 'authz_subject_conditions_user_id_department_id_company_post_id_role_id_ipv4_pattern_meta_subject_term_start_date_child_authz_subject_group_id_check';

    private const CHILD_IS_NOT_ITSELF = 'authz_subject_conditions_child_authz_subject_group_id_authz_subject_group_id_check';

    private const IPV4_PATTERN = 'authz_subject_conditions_ipv4_pattern_check';

    private const META_SUBJECT = 'authz_subject_conditions_meta_subject_check';

    private const TERM = 'authz_subject_conditions_term_start_date_term_end_date_check';

    private const COMPARISON_TARGET = 'authz_subject_conditions_operator_department_id_company_post_id_check';

    /**
     * @return array<string, array{string, ConditionOperator}>
     */
    public static function comparableTargetsAndOperators(): array
    {
        $cases = [];

        foreach (['forDepartment' => '組織', 'forCompanyPost' => '役職'] as $state => $label) {
            foreach (ConditionOperator::cases() as $operator) {
                $cases["{$label} {$operator->value}"] = [$state, $operator];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string|null, ConditionOperator}>
     */
    public static function incomparableTargetsWithAComparison(): array
    {
        return [
            'ユーザー lt' => [null, ConditionOperator::LessThan],
            'ユーザー ge' => [null, ConditionOperator::GreaterThanOrEqual],
            'ロール le' => ['forRole', ConditionOperator::LessThanOrEqual],
            'ロール gt' => ['forRole', ConditionOperator::GreaterThan],
            'IPv4 アドレス ge' => ['forIpv4Pattern', ConditionOperator::GreaterThanOrEqual],
            '認証状態 lt' => ['forMetaSubject', ConditionOperator::LessThan],
            '期間 le' => ['forTerm', ConditionOperator::LessThanOrEqual],
            '子の対象者 ge' => ['forChildSubjectGroup', ConditionOperator::GreaterThanOrEqual],
        ];
    }

    /**
     * 「1 つの相手だけ」に後から足した種類(外部キーで指さない種類と、子の対象者)。
     *
     * @return array<string, array{string}>
     */
    public static function targetsAddedLater(): array
    {
        return [
            'IPv4 アドレス' => ['forIpv4Pattern'],
            '認証状態' => ['forMetaSubject'],
            '期間' => ['forTerm'],
            '子の対象者' => ['forChildSubjectGroup'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validIpv4Patterns(): array
    {
        return [
            '本家の例 単一のアドレス' => ['192.168.0.1'],
            '本家の例 範囲' => ['192.168.[0-24].[0-254]'],
            '本家の例 ワイルドカード' => ['192.168.0.*'],
            '最小' => ['0.0.0.0'],
            '最大' => ['255.255.255.255'],
            '最長(39 文字)' => ['[255-255].[255-255].[255-255].[255-255]'],
            '範囲の m > n(DB では止めない)' => ['1.2.3.[24-0]'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidIpv4Patterns(): array
    {
        return [
            '256 以上' => ['256.1.1.1'],
            '部分が 3 つ' => ['1.2.3'],
            '部分が 5 つ' => ['1.2.3.4.5'],
            '先頭の 0' => ['01.2.3.4'],
            'CIDR' => ['1.2.3.4/24'],
            '範囲の片方が無い' => ['[1-].2.3.4'],
            '空文字' => [''],
            '前に空白' => [' 1.2.3.4'],
            '後に空白' => ['1.2.3.4 '],
            '末尾に改行' => ["1.2.3.4\n"],
            '範囲の数値の先頭の 0' => ['[01-2].1.1.1'],
            '全角の数字' => ['１.2.3.4'],
        ];
    }

    /**
     * @return array<string, array{string, string|null}>
     */
    public static function invalidTermsWithAStartDate(): array
    {
        return [
            '終了日が無い' => ['2026-01-01', null],
            '開始 = 終了' => ['2026-01-01', '2026-01-01'],
            '開始 > 終了' => ['2026-01-02', '2026-01-01'],
            '開始日が -infinity' => ['-infinity', '2026-01-01'],
            '終了日が infinity' => ['2026-01-01', 'infinity'],
        ];
    }

    #[DataProvider('comparableTargetsAndOperators')]
    public function test_accepts_and_reads_back_each_operator_for_a_department_or_a_post(string $state, ConditionOperator $operator): void
    {
        $condition = AuthzSubjectCondition::factory()->{$state}()->create(['operator' => $operator]);

        $this->assertSame($operator, $condition->refresh()->operator);
    }

    #[DataProvider('incomparableTargetsWithAComparison')]
    public function test_rejects_a_comparison_for_a_user_or_a_role(?string $state, ConditionOperator $operator): void
    {
        $factory = $state === null ? AuthzSubjectCondition::factory() : AuthzSubjectCondition::factory()->{$state}();

        $this->assertRejectedBy(self::COMPARISON_TARGET, fn () => $factory->create(['operator' => $operator]));
    }

    public function test_rejects_an_operator_outside_the_list(): void
    {
        $row = [...AuthzSubjectCondition::factory()->forDepartment()->makeOne()->getAttributes(), 'operator' => 'ne'];

        $this->assertRejectedBy('authz_subject_conditions_operator_check', fn () => DB::table('authz_subject_conditions')->insert($row));
    }

    public function test_fills_eq_when_the_operator_is_omitted(): void
    {
        $row = AuthzSubjectCondition::factory()->makeOne()->getAttributes();
        unset($row['operator']);

        $id = DB::table('authz_subject_conditions')->insertGetId($row);

        $this->assertSame(ConditionOperator::Equal, AuthzSubjectCondition::query()->findOrFail($id)->operator);
    }

    public function test_rejects_a_condition_without_a_target(): void
    {
        $this->assertRejectedBy(self::ONE_TARGET, fn () => AuthzSubjectCondition::factory()->create(['user_id' => null]));
    }

    public function test_rejects_a_condition_with_two_targets(): void
    {
        $role = Role::factory()->create();

        $this->assertRejectedBy(self::ONE_TARGET, fn () => AuthzSubjectCondition::factory()->create(['role_id' => $role->id]));
    }

    #[DataProvider('targetsAddedLater')]
    public function test_accepts_a_target_added_later_alone(string $state): void
    {
        $conditions = AuthzSubjectCondition::query()->count();

        AuthzSubjectCondition::factory()->{$state}()->create();

        $this->assertDatabaseCount('authz_subject_conditions', $conditions + 1);
    }

    #[DataProvider('targetsAddedLater')]
    public function test_rejects_a_target_added_later_together_with_a_user(string $state): void
    {
        $user = User::factory()->create();

        $this->assertRejectedBy(self::ONE_TARGET, fn () => AuthzSubjectCondition::factory()->{$state}()->create(['user_id' => $user->id]));
    }

    public function test_rejects_a_subject_group_as_its_own_child(): void
    {
        $condition = AuthzSubjectCondition::factory()->forChildSubjectGroup()->create();

        $this->assertRejectedBy(
            self::CHILD_IS_NOT_ITSELF,
            fn () => $condition->update(['child_authz_subject_group_id' => $condition->authz_subject_group_id]),
        );
    }

    public function test_reads_back_the_meta_subject_and_the_term(): void
    {
        $metaSubject = AuthzSubjectCondition::factory()->forMetaSubject(MetaSubject::Anonymous)->create();
        $term = AuthzSubjectCondition::factory()->forTerm('2026-04-01', '2026-10-01')->create();

        $this->assertSame(MetaSubject::Anonymous, $metaSubject->refresh()->meta_subject);
        $this->assertSame('2026-04-01', $term->refresh()->term_start_date?->toDateString());
        $this->assertSame('2026-10-01', $term->term_end_date?->toDateString());
    }

    #[DataProvider('validIpv4Patterns')]
    public function test_accepts_an_ipv4_pattern_in_the_format(string $pattern): void
    {
        $condition = AuthzSubjectCondition::factory()->forIpv4Pattern($pattern)->create();

        $this->assertSame($pattern, $condition->refresh()->ipv4_pattern);
    }

    #[DataProvider('invalidIpv4Patterns')]
    public function test_rejects_an_ipv4_pattern_outside_the_format(string $pattern): void
    {
        $this->assertRejectedBy(self::IPV4_PATTERN, fn () => AuthzSubjectCondition::factory()->forIpv4Pattern($pattern)->create());
    }

    public function test_rejects_a_meta_subject_outside_the_list(): void
    {
        $row = [...AuthzSubjectCondition::factory()->forMetaSubject()->makeOne()->getAttributes(), 'meta_subject' => 'guest'];

        $this->assertRejectedBy(self::META_SUBJECT, fn () => DB::table('authz_subject_conditions')->insert($row));
    }

    #[DataProvider('invalidTermsWithAStartDate')]
    public function test_rejects_a_term_that_is_not_a_finite_half_open_range(string $startDate, ?string $endDate): void
    {
        $row = [...AuthzSubjectCondition::factory()->forTerm()->makeOne()->getAttributes(), 'term_start_date' => $startDate, 'term_end_date' => $endDate];

        $this->assertRejectedBy(self::TERM, fn () => DB::table('authz_subject_conditions')->insert($row));
    }

    /**
     * 期間は開始日の列で数えるので、終了日だけの行は相手をユーザーにしても期間の検査制約が止める。
     */
    public function test_rejects_an_end_date_without_a_start_date(): void
    {
        $row = [...AuthzSubjectCondition::factory()->makeOne()->getAttributes(), 'term_end_date' => '2026-01-01'];

        $this->assertRejectedBy(self::TERM, fn () => DB::table('authz_subject_conditions')->insert($row));
    }
}
