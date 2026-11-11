<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordListQuery;
use App\Queries\Records\RecordListing;
use App\Queries\Records\RecordPageQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 業務モジュールのコードで受ける一覧の読み取りの返す形・条件・並び・ページ(docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の
 * 7.3 の表の RecordListQuery の行): 返す項目の既定と 1 ページ 20 件では画面の一覧と同じ結果、返す項目の指定、返す項目の素の型の並び、
 * 解釈できない条件を外すこと、最後のページに詰めるページ。判定と行の判定は RecordListApiTest。
 */
final class RecordListApiReadingTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    /** @var array<string, RecordField> */
    private array $fields = [];

    /**
     * ページ(5 件・2 件ずつ = 3 ページ): 1 未満は 1、最後のページより後は最後のページ。
     *
     * @return array<string, array{int, int}>
     */
    public static function pages(): array
    {
        return [
            '0 は 1' => [0, 1],
            '1 ページ目' => [1, 1],
            '2(最後の前)' => [2, 2],
            '3(最後)' => [3, 3],
            '4(最後の後)は最後' => [4, 3],
        ];
    }

    /**
     * 1 ページの件数の境(件数 4 に対して 1・2・件数 - 1・件数・件数 + 1・整数の最大。1 未満の 0 は作りの誤りの別のテスト)。
     * 各行 = 1 ページの件数・最後のページ・2 ページ目を求めたときのページ・そのページの前に飛ばす件数。
     *
     * @return array<string, array{int, int, int, int}>
     */
    public static function perPages(): array
    {
        return [
            '1 件' => [1, 4, 2, 1],
            '2 件' => [2, 2, 2, 2],
            '件数 - 1' => [3, 2, 2, 3],
            '件数' => [4, 1, 1, 0],
            '件数 + 1' => [5, 1, 1, 0],
            '整数の最大' => [PHP_INT_MAX, 1, 1, 0],
        ];
    }

    #[DataProvider('perPages')]
    public function test_any_number_a_page_from_one_up_to_the_largest_integer_gives_the_right_page(
        int $perPage,
        int $last,
        int $second,
        int $skipped,
    ): void {
        $this->projects();
        $ids = array_reverse($this->rows(4));

        $first = $this->listOf($this->requester(false), 'projects', perPage: $perPage)['records'];
        $next = $this->listOf($this->requester(false), 'projects', page: 2, perPage: $perPage)['records'];

        $this->assertSame([1, $last, 4], [$first['currentPage'], $first['lastPage'], $first['total']]);
        $this->assertSame(array_slice($ids, 0, min($perPage, 4)), array_column($first['data'], 'id'));
        $this->assertSame([$second, $last], [$next['currentPage'], $next['lastPage']]);
        $this->assertSame(array_slice($ids, $skipped, min($perPage, 4)), array_column($next['data'], 'id'));
    }

    public function test_with_the_listed_fields_and_twenty_a_page_it_equals_the_list_of_the_screen(): void
    {
        $this->projects();
        $this->rows(25);
        $cases = [
            [[], null, 2],
            [['title' => 'p-1', 'note' => 'x'], 'budget', 1],
            [['budget' => ['from' => '3']], '-due', 9],
        ];

        foreach ($cases as [$conditions, $sort, $page]) {
            $listed = $this->listOf($this->requester(false), 'projects', $conditions, $sort, $page);
            $screen = $this->screen($conditions, $sort, (string) $page);

            $this->assertSame($screen['records'], $listed['records']);
            $interpreted = ['filters' => $listed['query']['filters'], 'sort' => $listed['query']['sort']];
            $this->assertSame($screen['query'], $interpreted);
            $this->assertSame(['title', 'budget', 'due'], $listed['query']['fields']);
        }
    }

    public function test_the_given_fields_are_returned_without_details_or_unknown_codes(): void
    {
        $this->projects();
        $this->rows(2);

        $codes = ['note', 'tasks', 'missing', 'title', 'note', 7];
        $listed = $this->listOf($this->requester(false), 'projects', fieldCodes: $codes);
        $none = $this->listOf($this->requester(false), 'projects', sort: 'title', fieldCodes: []);

        $this->assertSame(['title', 'note'], $listed['query']['fields']);
        $this->assertSame(['title', 'note'], array_keys($listed['records']['data'][0]['values']));
        $this->assertSame([[], '-id'], [$none['query']['fields'], $none['query']['sort']]);
        $this->assertSame([], $none['records']['data'][0]['values']);
    }

    public function test_sorts_by_a_returned_plain_field_and_falls_back_to_the_default_for_a_field_not_returned(): void
    {
        $this->projects();
        $ids = $this->rows(3);

        $byNote = $this->listOf($this->requester(false), 'projects', sort: 'note', fieldCodes: ['title', 'note']);
        $byBudget = $this->listOf($this->requester(false), 'projects', sort: 'budget', fieldCodes: ['title', 'note']);

        $this->assertSame('note', $byNote['query']['sort']);
        $this->assertSame([$ids[1], $ids[2], $ids[0]], array_column($byNote['records']['data'], 'id'));
        $this->assertSame('-id', $byBudget['query']['sort']);
        $this->assertSame(array_reverse($ids), array_column($byBudget['records']['data'], 'id'));
    }

    public function test_uninterpretable_conditions_are_dropped_from_the_interpreted_query(): void
    {
        $this->projects();
        $ids = $this->rows(3);
        $conditions = ['budget' => ['from' => 'abc'], 'note' => 'x', 'missing' => '1', 'title' => 'p-1'];

        $listed = $this->listOf($this->requester(false), 'projects', $conditions);

        $this->assertSame(['title' => 'p-1'], $listed['query']['filters']);
        $this->assertSame([$ids[1]], array_column($listed['records']['data'], 'id'));
    }

    #[DataProvider('pages')]
    public function test_a_page_is_clamped_into_the_pages(int $page, int $current): void
    {
        $this->projects();
        $ids = array_reverse($this->rows(5));

        $listed = $this->listOf($this->requester(false), 'projects', page: $page, perPage: 2)['records'];

        $this->assertSame([$current, 3, 5], [$listed['currentPage'], $listed['lastPage'], $listed['total']]);
        $this->assertSame(array_slice($ids, ($current - 1) * 2, 2), array_column($listed['data'], 'id'));
    }

    public function test_an_empty_list_is_the_first_page_and_one_a_page_reads_one(): void
    {
        $this->projects();
        $empty = $this->listOf($this->requester(false), 'projects', page: 3, perPage: 2)['records'];
        $ids = $this->rows(2);
        $one = $this->listOf($this->requester(false), 'projects', perPage: 1)['records'];

        $this->assertSame([1, 1, 0, []], [$empty['currentPage'], $empty['lastPage'], $empty['total'], $empty['data']]);
        $this->assertSame([[$ids[1]], 2], [array_column($one['data'], 'id'), $one['lastPage']]);
    }

    /**
     * 業務モジュール projects: 一覧と検索の項目 title・budget・due、一覧に出さない文字列 note、明細 tasks。
     */
    private function projects(): void
    {
        $module = $this->recordModule('projects');
        $listed = ['is_listed' => true, 'is_searchable' => true];
        $this->fields = [
            'title' => $this->field($module, 'title', attributes: $listed),
            'budget' => $this->field($module, 'budget', RecordFieldType::Integer, $listed),
            'due' => $this->field($module, 'due', RecordFieldType::Date, $listed),
            'note' => $this->field($module, 'note'),
        ];
        $this->detailsField($module, 'tasks');
    }

    /**
     * projects のレコード(作った順。title = p-<n>、budget = n、due は id と逆の順、note は id と違う順)。
     *
     * @return list<int>
     */
    private function rows(int $count): array
    {
        $module = RecordModule::query()->where('code', 'projects')->sole();

        return array_map(fn (int $index) => Record::factory()->create([
            'record_module_id' => $module->id,
            'field_values' => [
                $this->fields['title']->id => "p-{$index}",
                $this->fields['budget']->id => $index,
                $this->fields['due']->id => sprintf('2026-10-%02d', 28 - $index),
                $this->fields['note']->id => ['c', 'a', 'b'][$index % 3],
            ],
        ])->id, range(0, $count - 1));
    }

    /**
     * 画面の一覧(内部の RecordListing に同じ値を渡す)。
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function screen(array $filters, ?string $sort, string $page): array
    {
        $date = self::day(self::TODAY);
        $opened = app(RecordPageQuery::class)
            ->open($this->requester(false), 'projects', $date, RecordModuleAction::Read);

        return app(RecordListing::class)->list($this->requester(false), $opened, $filters, $sort, $page, $date, 'ja');
    }

    /**
     * 判定を求める者(利用者 = 新しく作った有効なユーザー、ゲスト = ユーザーなし)。
     */
    private function requester(bool $user): Requester
    {
        return new Requester($user ? $this->user()->id : null, null);
    }

    /**
     * @param  array<array-key, mixed>  $conditions
     * @param  array<array-key, mixed>|null  $fieldCodes
     * @return array{records: array{data: list<array{id: int, values: array<string, mixed>, updatedAt: string|null}>,
     *     currentPage: int, lastPage: int, total: int},
     *     query: array{filters: array<string, mixed>, sort: string, fields: list<string>}}
     */
    private function listOf(
        Requester $requester,
        string $moduleCode,
        array $conditions = [],
        ?string $sort = null,
        int $page = 1,
        int $perPage = 20,
        ?array $fieldCodes = null,
    ): array {
        $date = self::day(self::TODAY);

        return app(RecordListQuery::class)
            ->list($requester, $moduleCode, $conditions, $sort, $page, $perPage, $fieldCodes, $date, 'ja');
    }
}
