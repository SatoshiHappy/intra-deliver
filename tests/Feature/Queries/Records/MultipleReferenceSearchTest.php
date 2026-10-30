<?php

namespace Tests\Feature\Queries\Records;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\RecordInput;
use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Master\DepartmentSet;
use App\Models\Master\User;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordListQuery;
use App\Queries\Records\RecordPageQuery;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsPartyInputs;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 一覧の検索の複数を選べる参照の条件(docs の 設計/業務データの入れ物/項目と規則/README.md 7 節のデシジョン表、
 * 規則と業務処理/画面と検査.md 13 節)。7 節の表の各行を通す(メソッド名の t1〜t6 = 表の上からの行)。数の境界と、型「ユーザーと組織」の
 * 相手の比べ方も通す。
 */
final class MultipleReferenceSearchTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsPartyInputs;
    use BuildsRecordModules;

    private DepartmentSet $set;

    /** {@see self::sampleRecords()} の 1 件目の相手(ユーザー)の id */
    private int $firstMember;

    protected function setUp(): void
    {
        parent::setUp();
        $module = $this->recordModule('meetings');
        $searchable = ['is_searchable' => true];
        $multiple = ['is_multiple' => true, ...$searchable];
        $this->field($module, 'title');
        $this->field($module, 'members', RecordFieldType::User, $multiple);
        $this->field($module, 'sections', RecordFieldType::Department, $multiple);
        $this->field($module, 'parties', RecordFieldType::UserOrDepartment, $multiple);
        $this->field($module, 'host', RecordFieldType::UserOrDepartment, $searchable);
        $this->field($module, 'owner', RecordFieldType::User, $searchable);
        $this->set = DepartmentSet::factory()->create();
    }

    public function test_t1_no_targets_is_no_condition(): void
    {
        $ids = $this->sampleRecords();

        $this->assertSame([$ids, []], $this->search(['members' => []]));
        $this->assertSame([$ids, []], $this->search(['parties' => []]));
    }

    public function test_t2_101_targets_is_no_condition_and_100_targets_is_a_condition(): void
    {
        $users = array_map(fn () => $this->user(), range(1, 101));
        $all = $this->create(['members' => self::ids(array_slice($users, 0, 100))]);
        $fewer = $this->create(['members' => self::ids(array_slice($users, 0, 99))]);
        $ids = array_map(fn (User $user) => (string) $user->id, $users);

        $hundred = $this->search(['members' => array_slice($ids, 0, 100)]);
        $tooMany = $this->search(['members' => $ids]);

        $this->assertSame([[$all], ['members' => array_slice($ids, 0, 100)]], $hundred);
        $this->assertSame([[$fewer, $all], []], $tooMany);
    }

    /**
     * @param  list<string>  $unreadable  読めない相手を混ぜた並び(先頭は読める相手に替える)
     */
    #[TestWith(['members', ['x']])]
    #[TestWith(['members', ['1.5']])]
    #[TestWith(['members', ['0']])]
    #[TestWith(['members', ['user:1']])]
    #[TestWith(['parties', ['3']])]
    #[TestWith(['parties', ['role:3']])]
    #[TestWith(['parties', ['user:x']])]
    #[TestWith(['parties', ['']])]
    public function test_t3_an_unreadable_target_makes_the_whole_condition_none(string $code, array $unreadable): void
    {
        $ids = $this->sampleRecords();
        $readable = $code === 'members' ? (string) $this->firstMember : 'user:'.$this->firstMember;

        $this->assertSame([$ids, []], $this->search([$code => $unreadable]));
        $this->assertSame([$ids, []], $this->search([$code => [$readable, ...$unreadable]]));
    }

    public function test_t4_one_target_finds_records_that_hold_it_among_their_values(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $section = $this->department($this->set);
        $both = $this->create([
            'sections' => [$section->id],
            'parties' => [self::partyInputOf($a), self::partyInputOf($b)],
        ]);
        $other = $this->create(['parties' => [self::partyInputOf($b)]]);

        $sections = [(string) $section->id];
        [$userA, $userB] = ["user:{$a->id}", "user:{$b->id}"];

        $this->assertSame([[$both], ['sections' => $sections]], $this->search(['sections' => $sections]));
        $this->assertSame([[$both], ['parties' => [$userA]]], $this->search(['parties' => [" {$userA} "]]));
        $this->assertSame([[$other, $both], ['parties' => [$userB]]], $this->search(['parties' => [$userB]]));
    }

    public function test_t5_the_same_target_twice_is_no_condition(): void
    {
        $ids = $this->sampleRecords();
        $member = (string) $this->firstMember;

        $this->assertSame([$ids, []], $this->search(['members' => [$member, $member]]));
        $this->assertSame([$ids, []], $this->search(['parties' => ["user:{$member}", " user:{$member}"]]));
    }

    public function test_t6_two_targets_find_only_records_that_hold_both(): void
    {
        [$a, $b] = [$this->user(), $this->user()];
        $both = $this->create(['members' => [$a->id, $b->id]]);
        $this->create(['members' => [$a->id]]);
        $this->create(['members' => [$b->id]]);

        $found = $this->search(['members' => [(string) $b->id, (string) $a->id]]);

        $this->assertSame([[$both], ['members' => [(string) $b->id, (string) $a->id]]], $found);
    }

    public function test_a_user_and_a_department_find_only_records_that_hold_both(): void
    {
        $user = $this->user();
        $section = $this->department($this->set);
        $both = $this->create(['parties' => [self::partyInputOf($section), self::partyInputOf($user)]]);
        $this->create(['parties' => [self::partyInputOf($user)]]);
        $this->create(['parties' => [self::partyInputOf($section)]]);

        $found = $this->search(['parties' => ["user:{$user->id}", "department:{$section->id}"]]);

        $this->assertSame([$both], $found[0]);
    }

    public function test_a_user_and_a_department_of_the_same_id_are_different_targets(): void
    {
        [$user, $department] = $this->userAndDepartmentWithTheSameId($this->set);
        $withUser = $this->create(['parties' => [self::partyInputOf($user)], 'host' => self::partyInputOf($user)]);
        $withDepartment = $this->create([
            'parties' => [self::partyInputOf($department)],
            'host' => self::partyInputOf($department),
        ]);

        $this->assertSame([$withUser], $this->search(['parties' => ["user:{$user->id}"]])[0]);
        $this->assertSame([$withDepartment], $this->search(['parties' => ["department:{$department->id}"]])[0]);
        $this->assertSame([$withUser], $this->search(['host' => "user:{$user->id}"])[0]);
        $this->assertSame([$withDepartment], $this->search(['host' => "department:{$department->id}"])[0]);
    }

    public function test_a_department_does_not_find_records_that_hold_its_members(): void
    {
        $member = $this->user();
        $section = $this->department($this->set);
        $this->join($member, $section, isMain: true);
        $withMember = $this->create(['parties' => [self::partyInputOf($member)], 'sections' => []]);
        $withSection = $this->create(['parties' => [self::partyInputOf($section)], 'sections' => [$section->id]]);

        $this->assertSame([$withSection], $this->search(['parties' => ["department:{$section->id}"]])[0]);
        $this->assertNotContains($withMember, $this->search(['sections' => [(string) $section->id]])[0]);
    }

    public function test_a_single_value_field_takes_one_target_and_an_array_is_no_condition(): void
    {
        $user = $this->user();
        $section = $this->department($this->set);
        $withUser = $this->create(['owner' => $user->id, 'host' => self::partyInputOf($user)]);
        $withSection = $this->create(['host' => self::partyInputOf($section)]);
        $all = [$withSection, $withUser];

        [$owner, $host] = [(string) $user->id, "department:{$section->id}"];

        $this->assertSame([[$withUser], ['owner' => $owner]], $this->search(['owner' => $owner]));
        $this->assertSame([[$withSection], ['host' => $host]], $this->search(['host' => $host]));
        $this->assertSame([$all, []], $this->search(['owner' => [(string) $user->id]]));
        $this->assertSame([$all, []], $this->search(['host' => ["user:{$user->id}"]]));
        $this->assertSame([$all, []], $this->search(['host' => (string) $user->id]));
    }

    public function test_a_multiple_field_takes_only_a_list(): void
    {
        $ids = $this->sampleRecords();
        $member = (string) $this->firstMember;

        $this->assertSame([$ids, []], $this->search(['members' => $member]));
        $this->assertSame([$ids, []], $this->search(['members' => ['a' => $member]]));
        $this->assertSame([$ids, []], $this->search(['members' => [[$member]]]));
    }

    /**
     * 相手を持つレコード 2 件(members と parties に同じユーザー)。1 件目の相手を {@see self::$firstMember} に置く。
     *
     * @return list<int> レコードの id(一覧の並び = 新しい順)
     */
    private function sampleRecords(): array
    {
        [$a, $b] = [$this->user(), $this->user()];
        $first = $this->create(['members' => [$a->id], 'parties' => [self::partyInputOf($a)]]);
        $second = $this->create(['members' => [$b->id], 'parties' => [self::partyInputOf($b)]]);
        $this->firstMember = $a->id;

        return [$second, $first];
    }

    /**
     * @param  list<User>  $users
     * @return list<int>
     */
    private static function ids(array $users): array
    {
        return array_map(fn (User $user) => $user->id, $users);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function create(array $values): int
    {
        $input = new RecordInput(['title' => '会議', ...$values]);

        return app(CreateRecord::class)->execute($this->contextFor(), 'meetings', $input);
    }

    /**
     * 検索条件で当たったレコードの id(新しい順)と、解釈した条件。
     *
     * @param  array<string, mixed>  $filters
     * @return array{list<int>, array<string, mixed>}
     */
    private function search(array $filters): array
    {
        $requester = new Requester(null, null);
        $date = CarbonImmutable::parse(self::TODAY);
        $opened = app(RecordPageQuery::class)->open($requester, 'meetings', $date, RecordModuleAction::Read);
        $listed = app(RecordListQuery::class)->list($requester, $opened, $filters, null, null, $date, 'ja');

        return [array_column($listed['records']['data'], 'id'), $listed['query']['filters']];
    }
}
