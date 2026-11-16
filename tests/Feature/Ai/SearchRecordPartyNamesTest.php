<?php

namespace Tests\Feature\Ai;

use App\Actions\Ai\ToolCalls\PartyCandidates;
use App\Enums\Ai\AiInstructionTemplate;
use App\Models\Master\Department;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TransactionalTestCase;

/**
 * 探すの条件のユーザー・組織の相手のうち、名前の物を引く行(docs の 設計/AI/道具と権限/README.md 10.2 の「ユーザー・組織の名前」の値の形の表、
 * 決まらないとき = 設計/AI/道具と権限/道具の判定と今の権限.md 10.3 の B1)。表の行はテストの名前の頭かデータの鍵で示す。名前を引かない行 =
 * {@see SearchRecordPartiesTest}。
 */
final class SearchRecordPartyNamesTest extends TransactionalTestCase
{
    use ArrangesSearchParties;
    use BuildsToolRecords;
    use RunsRecordTools;

    protected function setUp(): void
    {
        parent::setUp();
        $this->arrangeSearchParties();
    }

    /** 名前の物は完全一致の 1 人に決まり(部分一致の別の人がいても)、相手の名前と所属が結果に添う */
    public function test_row_searchable_resolved_name_the_one_exact_match(): void
    {
        $hit = $this->task(['title' => '当たり', 'charge' => $this->ichiro->id]);
        $this->task(['title' => '外れ', 'charge' => $this->user()->id]);

        $body = self::body($this->search(['charge' => ['name' => '探索 一郎']]));

        $this->assertSame([$hit], array_column($body['records'], 'id'));
        $party = ['field' => 'charge', 'type' => 'user', 'id' => $this->ichiro->id];
        $party = [...$party, 'name' => '探索 一郎', 'affiliation' => '総務部'];
        $this->assertSame([$party], $body['parties']);
        $this->assertArrayNotHasKey('ignored', $body);
        $this->assertSame(['探索 一郎'], $this->candidates->names);
    }

    /** 型「組織」と型「ユーザーと組織」の名前の物(ユーザーと組織の両方を引き、合わせて 1 つなら決まる) */
    public function test_row_searchable_resolved_name_of_a_department(): void
    {
        $department = ['type' => 'department', 'id' => $this->general->id];
        $hit = $this->task(['title' => '総務', 'section' => $this->general->id, 'party' => $department]);
        $this->task(['title' => '人事', 'section' => Department::query()->where('code', 'human-resources')->sole()->id]);

        $body = self::body($this->search(['section' => ['name' => '総務部'], 'party' => ['name' => '総務部']]));

        $this->assertSame([$hit], array_column($body['records'], 'id'));
        $parties = array_map(
            fn (array $party): array => [$party['field'], $party['type'], $party['id']],
            $body['parties'],
        );
        $general = $this->general->id;
        $this->assertSame([['section', 'department', $general], ['party', 'department', $general]], $parties);
    }

    /** 複数の相手の列は要素ごとに直す(id の形と名前の物を混ぜてよい)。列のすべてを値に持つレコードに当たる */
    public function test_rows_searchable_id_form_and_resolved_name_in_a_list(): void
    {
        $other = $this->userNamedOver([['2020-01-01', null, '別の 人']]);
        $both = $this->task(['title' => '両方', 'members' => [$this->ichiro->id, $other->id]]);
        $this->task(['title' => '片方', 'members' => [$this->ichiro->id]]);

        $body = self::body($this->search(['members' => [(string) $other->id, ['name' => '探索 一郎']]]));

        $this->assertSame([$both], array_column($body['records'], 'id'));
        $this->assertSame([$other->id, $this->ichiro->id], array_column($body['parties'], 'id'));
    }

    /** 同じ名前の人が 2 人いれば候補(条件に書く値・名前・所属)を返し、道具を呼ばない */
    public function test_row_searchable_unresolved_name_two_exact_matches(): void
    {
        $twin = $this->userNamedOver([['2020-01-01', null, '探索 一郎']]);

        $content = $this->search(['charge' => ['name' => '探索 一郎']]);

        $error = $this->validationError($content, 'c1');
        $this->assertStringContainsString('条件 `charge` の名前「探索 一郎」の相手が 1 つに決まらない。候補:', $error);
        $this->assertStringContainsString("- `{$this->ichiro->id}` 探索 一郎(ユーザー。所属: 総務部)", $error);
        $this->assertStringContainsString("- `{$twin->id}` 探索 一郎(ユーザー)", $error);
        $this->assertStringContainsString('利用者に選んでもらい', $error);
        $this->assertSame([], $this->reads->calls);
    }

    /** 完全一致が無ければ(姓だけ・部分一致だけでも)決めず、部分一致の候補を返す。何も当たらなければ当たらなかったことを返す */
    public function test_row_searchable_unresolved_name_a_partial_or_no_match(): void
    {
        $partial = $this->validationError($this->search(['charge' => ['name' => '探索']]), 'c1');
        $none = $this->validationError($this->search(['charge' => ['name' => '居ない 人']], 'c2'), 'c2');

        $this->assertStringContainsString('1 つに決まらない', $partial);
        $this->assertStringContainsString("- `{$this->ichiro->id}` 探索 一郎", $partial);
        $this->assertStringContainsString('条件 `charge` の名前「居ない 人」に当たる相手がいない。', $none);
    }

    /** 複数の相手の列の一部だけが決まらなければ、決まらない要素の誤りを返し、道具を呼ばない */
    public function test_row_searchable_unresolved_name_in_a_list(): void
    {
        $content = $this->search(['members' => [(string) $this->ichiro->id, ['name' => '居ない 人']]]);

        $error = $this->validationError($content, 'c1');
        $this->assertStringContainsString('条件 `members` の名前「居ない 人」に当たる相手がいない。', $error);
        $this->assertStringNotContainsString((string) $this->ichiro->id, $error);
        $this->assertSame([], $this->reads->calls);
    }

    /**
     * 引いた候補の数(上限の 1 つ手前・上限)。各行 = 候補の数・決まるか。
     *
     * @return array<string, array{int, bool}>
     */
    public static function candidateCounts(): array
    {
        return [
            '名前の物で決まる行: 候補が上限の 1 つ手前' => [PartyCandidates::LIMIT - 1, true],
            '名前の物で決まらない行: 候補が上限に達した(完全一致が 1 人でも)' => [PartyCandidates::LIMIT, false],
        ];
    }

    #[DataProvider('candidateCounts')]
    public function test_rows_searchable_resolved_or_unresolved_name_at_the_candidate_limit(
        int $count,
        bool $resolves,
    ): void {
        foreach (range(3, $count) as $index) {
            $this->userNamedOver([['2020-01-01', null, "探索 一郎{$index}"]]);
        }

        $content = $this->search(['charge' => ['name' => '探索 一郎']]);

        $this->assertSame($resolves ? null : AiInstructionTemplate::ToolError->value, $content['template']);
    }
}
