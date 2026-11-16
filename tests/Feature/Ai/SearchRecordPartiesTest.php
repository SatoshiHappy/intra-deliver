<?php

namespace Tests\Feature\Ai;

use App\Models\Master\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 探すの条件のユーザー・組織の相手のうち、名前を引かない行(docs の 設計/AI/道具と権限/README.md 10.2 の「ユーザー・組織の名前」の値の形の表)。
 * 表の行はテストの名前の頭(test_row_<条件に使えるか>_<値の形>)で示す。引かないことは、結果でなく名前の引き当ての呼ばれた回数で確かめる
 * (完全一致する名前でも引かないことを確かめるため)。名前を引く行 = {@see SearchRecordPartyNamesTest}。
 */
final class SearchRecordPartiesTest extends TransactionalTestCase
{
    use ArrangesSearchParties;
    use BuildsToolRecords;
    use RunsRecordTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeSearchParties();
    }

    /** 基準日に名前を読めない相手(期間の切れた人)は「名前を読めない」とだけ添える。条件の id に有効かの確かめは掛けない */
    public function test_row_searchable_id_form_an_unreadable_party_is_noted(): void
    {
        $retired = $this->userNamedOver([['2020-01-01', null, '退職 次郎']]);
        $hit = $this->task(['title' => '退職前', 'charge' => $retired->id]);
        $retired->userTerms()->update(['end_date' => '2026-10-01']);

        $body = self::body($this->search(['charge' => (string) $retired->id]));

        $this->assertSame([$hit], array_column($body['records'], 'id'));
        $party = ['field' => 'charge', 'type' => 'user', 'id' => $retired->id, 'note' => '名前を読めない'];
        $this->assertSame([$party], $body['parties']);
    }

    /** 無い id(一度も無かった id)も道具の検証の誤りにせず一覧の口へ渡し、「名前を読めない」とだけ添える */
    public function test_row_searchable_id_form_a_missing_id_goes_to_the_list(): void
    {
        $this->task(['title' => '担当あり', 'charge' => $this->ichiro->id]);
        $missing = (int) User::query()->max('id') + 1000;

        $body = self::body($this->search(['charge' => (string) $missing]));

        $this->assertSame([], $body['records']);
        $party = ['field' => 'charge', 'type' => 'user', 'id' => $missing, 'note' => '名前を読めない'];
        $this->assertSame([$party], $body['parties']);
        $this->assertArrayNotHasKey('ignored', $body);
    }

    /**
     * 型「ユーザーと組織」の id の形。各行 = 相手の種類。
     *
     * @return array<string, array{'user'|'department'}>
     */
    public static function userOrDepartmentIds(): array
    {
        return ['user:<id>' => ['user'], 'department:<id>' => ['department']];
    }

    /** 型「ユーザーと組織」の id の形は、種類と id で一覧の口に当たり、相手を種類つきで添える */
    #[DataProvider('userOrDepartmentIds')]
    public function test_row_searchable_id_form_of_user_or_department(string $kind): void
    {
        $user = $this->task(['title' => '人', 'party' => ['type' => 'user', 'id' => $this->ichiro->id]]);
        $department = $this->task(['title' => '組織', 'party' => ['type' => 'department', 'id' => $this->general->id]]);
        $id = $kind === 'user' ? $this->ichiro->id : $this->general->id;

        $body = self::body($this->search(['party' => "{$kind}:{$id}"]));

        $this->assertSame([$kind === 'user' ? $user : $department], array_column($body['records'], 'id'));
        $this->assertSame([['party', $kind, $id]], array_map(
            fn (array $party): array => [$party['field'], $party['type'], $party['id']],
            $body['parties'],
        ));
        $this->assertSame([], $this->candidates->names);
    }

    /**
     * id の形でも名前の物でもない相手の値。各行 = 項目のコード・条件の値。
     *
     * @return array<string, array{string, mixed}>
     */
    public static function neitherValues(): array
    {
        return [
            '型「ユーザー」: ほかの鍵を持つ名前の物' => ['charge', ['name' => '探索 一郎', 'type' => 'user']],
            '型「ユーザー」: 数' => ['charge', 12],
            '型「ユーザー」: 名前の文字列' => ['charge', '探索 一郎'],
            '型「ユーザーと組織」: 種類の無い id' => ['party', (string) 12],
        ];
    }

    /** id の形でも名前の物でもない相手の値は引かず、一覧の口が外す */
    #[DataProvider('neitherValues')]
    public function test_row_searchable_neither_is_dropped(string $code, mixed $value): void
    {
        $this->task(['title' => '担当あり', 'charge' => $this->ichiro->id]);

        $body = self::body($this->search([$code => $value]));

        $this->assertSame([$code], $body['ignored']['conditions']);
        $this->assertCount(1, $body['records']);
        $this->assertArrayNotHasKey('parties', $body);
        $this->assertSame([], $this->candidates->names);
    }

    /**
     * 条件に使えない項目(実行の始めの定義)の名前の物。各行 = 項目のコード・条件の値。
     *
     * @return array<string, array{string, mixed}>
     */
    public static function unsearchedNames(): array
    {
        return [
            '型「ユーザー」の完全一致する名前の物' => ['owner', ['name' => '探索 一郎']],
            '型「組織」の完全一致する名前の物' => ['unsearched_section', ['name' => '総務部']],
            '複数を選べる項目の名前の物の列' => ['unsearched_members', [['name' => '居ない 人'], ['name' => '探索 一郎']]],
        ];
    }

    /** 条件に使えない項目の名前の物は、完全一致する名前でも引かず、道具の検証の誤りにならずに外した物になる */
    #[DataProvider('unsearchedNames')]
    public function test_row_not_searchable_any_value_is_dropped_without_resolving(
        string $code,
        mixed $value,
    ): void {
        $this->task(['title' => '担当あり', 'owner' => $this->ichiro->id]);

        $body = self::body($this->search([$code => $value]));

        $this->assertSame([$code], $body['ignored']['conditions']);
        $this->assertCount(1, $body['records']);
        $this->assertArrayNotHasKey('parties', $body);
        $this->assertSame([], $this->candidates->names);
        $this->assertSame(['RecordListQuery::list'], $this->reads->calls);
    }

    /** 条件に使える相手でない型(選択)の名前の物は引かず、一覧の口の規則で外す */
    public function test_row_searchable_another_type_follows_the_list_rule(): void
    {
        $this->task(['title' => '開いた', 'status' => 'open']);

        $body = self::body($this->search(['status' => ['name' => 'open']]));

        $this->assertSame(['status'], $body['ignored']['conditions']);
        $this->assertCount(1, $body['records']);
        $this->assertArrayNotHasKey('parties', $body);
        $this->assertSame([], $this->candidates->names);
        $this->assertSame(['RecordListQuery::list'], $this->reads->calls);
    }
}
