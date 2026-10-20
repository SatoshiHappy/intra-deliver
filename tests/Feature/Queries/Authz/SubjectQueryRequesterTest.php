<?php

namespace Tests\Feature\Queries\Authz;

use App\Enums\Authz\MetaSubject;
use App\Enums\Authz\SubjectCombination;
use App\Models\Authz\AuthzSubjectCondition;
use App\Models\Authz\AuthzSubjectGroup;
use App\Models\Authz\Role;
use App\Models\Master\DepartmentSet;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\SubjectQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\TransactionalTestCase;

/**
 * 判定を求める者(ユーザー(いないことがある)と接続元の IPv4 アドレス(無いことがある))と、外部キーで指さない対象者の種類
 * (docs の 設計/共通マスタ/ロールと認可/対象者.md: IPv4 アドレス = 4 つの部分の照合、認証状態 = d に有効なユーザーの有無(一方だけ)、
 * 期間 = [開始日, 終了日))。d に有効でない・存在しないユーザーはユーザーなし(anonymous)として扱う。
 * 外部キーで指す種類(ユーザー・組織・役職・ロール)の比較は SubjectQueryTest。
 */
final class SubjectQueryRequesterTest extends TransactionalTestCase
{
    use BuildsCommonMaster;

    private const DATE = '2024-04-01';

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function ipv4Patterns(): array
    {
        return [
            '単一のアドレス 同じ' => ['192.168.0.1', '192.168.0.1', true],
            '単一のアドレス 違う' => ['192.168.0.1', '192.168.0.2', false],
            'ワイルドカード 合う' => ['192.168.0.*', '192.168.0.255', true],
            'ワイルドカード 別の部分が違う' => ['192.168.0.*', '192.168.1.0', false],
            '範囲 下端' => ['192.168.[0-24].1', '192.168.0.1', true],
            '範囲 上端' => ['192.168.[0-24].1', '192.168.24.1', true],
            '範囲 上端の外' => ['192.168.[0-24].1', '192.168.25.1', false],
            '範囲 m > n はどの値にも合わない' => ['192.168.0.[24-0]', '192.168.0.10', false],
            'すべてワイルドカード' => ['*.*.*.*', '0.0.0.0', true],
        ];
    }

    #[DataProvider('ipv4Patterns')]
    public function test_matches_the_address_of_the_connection_against_the_ipv4_pattern(string $pattern, string $address, bool $expected): void
    {
        $group = $this->subjectGroup(SubjectCombination::Any, [['ipv4_pattern' => $pattern]]);

        $this->assertSame($expected, $this->isMatched(new Requester(null, $address), $group));
    }

    public function test_an_ipv4_condition_does_not_match_without_an_address_of_the_connection(): void
    {
        $group = $this->subjectGroup(SubjectCombination::Any, [['ipv4_pattern' => '*.*.*.*']]);

        $this->assertFalse($this->isMatched(new Requester($this->user()->id, null), $group));
    }

    public function test_an_effective_user_is_authenticated_and_not_anonymous(): void
    {
        $user = $this->user();
        [$authenticated, $anonymous] = $this->metaSubjectGroups();

        $this->assertTrue($this->isMatched(new Requester($user->id, null), $authenticated));
        $this->assertFalse($this->isMatched(new Requester($user->id, null), $anonymous));
    }

    public function test_a_requester_without_a_user_is_anonymous(): void
    {
        [$authenticated, $anonymous] = $this->metaSubjectGroups();

        $this->assertFalse($this->isMatched(new Requester(null, null), $authenticated));
        $this->assertTrue($this->isMatched(new Requester(null, null), $anonymous));
    }

    public function test_a_user_who_is_not_effective_on_the_date_is_treated_as_anonymous(): void
    {
        $retired = $this->user([['2020-01-01', self::DATE]]);
        [$authenticated, $anonymous] = $this->metaSubjectGroups();

        $this->assertTrue($this->isMatched(new Requester($retired->id, null), $authenticated, '2024-03-31'));
        $this->assertFalse($this->isMatched(new Requester($retired->id, null), $authenticated));
        $this->assertTrue($this->isMatched(new Requester($retired->id, null), $anonymous));
    }

    public function test_a_user_id_that_does_not_exist_is_treated_as_anonymous(): void
    {
        $missing = new Requester($this->user()->id + 1000, null);
        [$authenticated, $anonymous] = $this->metaSubjectGroups();

        $this->assertFalse($this->isMatched($missing, $authenticated));
        $this->assertTrue($this->isMatched($missing, $anonymous));
    }

    public function test_a_requester_without_a_user_matches_no_user_department_post_or_role_condition(): void
    {
        $set = DepartmentSet::factory()->create(['is_default' => true]);
        $department = $this->department($set, isRoot: true);
        $post = $this->companyPost($set, 40);
        $role = Role::factory()->create();
        $user = $this->user();
        $this->assignPost($this->join($user, $department, isMain: true), $post);
        $this->grant($user, $role);
        $group = $this->subjectGroup(SubjectCombination::Any, [
            ['user_id' => $user->id],
            ['department_id' => $department->id],
            ['company_post_id' => $post->id],
            ['role_id' => $role->id],
        ]);

        $this->assertTrue($this->isMatched(new Requester($user->id, null), $group));
        $this->assertFalse($this->isMatched(new Requester(null, '192.168.0.1'), $group));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function datesAroundATerm(): array
    {
        return [
            '開始日の前日' => ['2024-03-31', false],
            '開始日' => ['2024-04-01', true],
            '終了日の前日' => ['2024-04-30', true],
            '終了日' => ['2024-05-01', false],
        ];
    }

    #[DataProvider('datesAroundATerm')]
    public function test_a_term_condition_matches_from_the_start_date_until_the_day_before_the_end_date(string $date, bool $expected): void
    {
        $group = $this->subjectGroup(SubjectCombination::Any, [['term_start_date' => '2024-04-01', 'term_end_date' => '2024-05-01']]);

        $this->assertSame($expected, $this->isMatched(new Requester(null, null), $group, $date));
    }

    public function test_all_with_a_role_and_a_term_permits_the_holders_only_within_the_term(): void
    {
        $role = Role::factory()->create();
        $user = $this->user();
        $this->grant($user, $role);
        $group = $this->subjectGroup(SubjectCombination::All, [['role_id' => $role->id], ['term_start_date' => '2024-04-01', 'term_end_date' => '2024-05-01']]);

        $this->assertTrue($this->isMatched(new Requester($user->id, null), $group, '2024-04-15'));
        $this->assertFalse($this->isMatched(new Requester($user->id, null), $group, '2024-05-01'));
        $this->assertFalse($this->isMatched(new Requester(null, null), $group, '2024-04-15'));
    }

    public function test_any_with_an_address_and_anonymous_matches_either_of_them(): void
    {
        $user = $this->user();
        $group = $this->subjectGroup(SubjectCombination::Any, [['ipv4_pattern' => '10.0.0.*'], ['meta_subject' => MetaSubject::Anonymous]]);

        $this->assertTrue($this->isMatched(new Requester($user->id, '10.0.0.5'), $group));
        $this->assertTrue($this->isMatched(new Requester(null, '192.168.0.1'), $group));
        $this->assertFalse($this->isMatched(new Requester($user->id, '192.168.0.1'), $group));
    }

    public function test_all_with_an_address_and_anonymous_needs_both(): void
    {
        $user = $this->user();
        $group = $this->subjectGroup(SubjectCombination::All, [['ipv4_pattern' => '10.0.0.*'], ['meta_subject' => MetaSubject::Anonymous]]);

        $this->assertTrue($this->isMatched(new Requester(null, '10.0.0.5'), $group));
        $this->assertFalse($this->isMatched(new Requester($user->id, '10.0.0.5'), $group));
        $this->assertFalse($this->isMatched(new Requester(null, null), $group));
    }

    /**
     * 認証状態の対象者 2 つ(authenticated・anonymous)。
     *
     * @return array{AuthzSubjectGroup, AuthzSubjectGroup}
     */
    private function metaSubjectGroups(): array
    {
        return [
            $this->subjectGroup(SubjectCombination::Any, [['meta_subject' => MetaSubject::Authenticated]]),
            $this->subjectGroup(SubjectCombination::Any, [['meta_subject' => MetaSubject::Anonymous]]),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $conditions  条件ごとの列(相手の列 1 つ)
     */
    private function subjectGroup(SubjectCombination $combination, array $conditions): AuthzSubjectGroup
    {
        $group = AuthzSubjectGroup::factory()->create(['combination' => $combination]);

        foreach ($conditions as $condition) {
            AuthzSubjectCondition::factory()->create(['authz_subject_group_id' => $group->id, 'user_id' => null, ...$condition]);
        }

        return $group;
    }

    private function isMatched(Requester $requester, AuthzSubjectGroup $group, string $date = self::DATE): bool
    {
        return app(SubjectQuery::class)->matchesAny($requester, [$group->id], self::day($date));
    }
}
