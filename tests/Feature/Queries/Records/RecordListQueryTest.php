<?php

namespace Tests\Feature\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordListQuery;
use App\Queries\Records\RecordPageQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsCommonMaster;
use Tests\Support\BuildsRecordModules;
use Tests\Support\TransactionalTestCase;

/**
 * 業務の一覧(docs の 設計/業務データの入れ物/項目と規則/README.md 7 節、
 * 設計/業務データの入れ物/画面/業務の画面.md 2.1 の records・query、
 * 設計/業務データの入れ物/項目と規則/規則と業務処理/画面と検査.md 13 節の「一覧」。判定は 設計/業務データの入れ物/認可とメニュー/README.md 2.2 の一覧 = read)。
 */
final class RecordListQueryTest extends TransactionalTestCase
{
    use BuildsCommonMaster;
    use BuildsRecordModules;

    private RecordModule $module;

    /** @var array<string, RecordField> */
    private array $fields = [];

    /**
     * 検索条件 → 当たるレコード(下の rows() の添字)と、解釈した検索条件。
     *
     * @return array<string, array{array<string, mixed>, list<int>, array<string, mixed>}>
     */
    public static function filters(): array
    {
        return [
            '文字列の部分一致(大文字小文字を区別しない)' => [['title' => ' PORTAL '], [1, 0], ['title' => 'PORTAL']],
            '文字列の % は文字' => [['title' => '100%'], [2], ['title' => '100%']],
            '文字列の _ は文字' => [['title' => 'a_b'], [3], ['title' => 'a_b']],
            '整数の範囲の下限だけ' => [['budget' => ['from' => '200']], [3, 2], ['budget' => ['from' => '200', 'to' => null]]],
            '整数の範囲の両端(含む)' => [['budget' => ['from' => '100', 'to' => '200']], [2, 1, 0], ['budget' => ['from' => '100', 'to' => '200']]],
            '小数の範囲の上限だけ' => [['rate' => ['to' => '1.5']], [1, 0], ['rate' => ['from' => null, 'to' => '1.5']]],
            '日付の範囲(下限・上限ちょうどを含み、上限の 1 日後を含まない)' => [['due' => ['from' => '2026-10-01', 'to' => '2026-10-02']], [2, 1], ['due' => ['from' => '2026-10-01', 'to' => '2026-10-02']]],
            '日時の範囲(時差つき。下限・上限ちょうどを含み、上限の 1 秒後を含まない)' => [['starts_at' => ['from' => '2026-10-11T09:00:00+09:00', 'to' => '2026-10-11T09:59:59+09:00']], [2, 0], ['starts_at' => ['from' => '2026-10-11T09:00:00+09:00', 'to' => '2026-10-11T09:59:59+09:00']]],
            '日時の時差の無い値は指定なし' => [['starts_at' => ['from' => '2026-10-11T09:00']], [3, 2, 1, 0], []],
            'UTC で年が 0 になる日時は指定なし' => [['starts_at' => ['from' => '0001-01-01T05:00:00+09:00']], [3, 2, 1, 0], []],
            '真偽の真' => [['urgent' => 'true'], [1], ['urgent' => 'true']],
            '真偽の偽(キーの無いレコードも偽)' => [['urgent' => 'false'], [3, 2, 0], ['urgent' => 'false']],
            '選択の一致' => [['status' => 'done'], [0], ['status' => 'done']],
            '選択肢に無いコードは指定なし' => [['status' => 'closed'], [3, 2, 1, 0], []],
            '検索条件にしない項目は指定なし' => [['note' => 'x'], [3, 2, 1, 0], []],
            '数として読めない値は指定なし' => [['budget' => ['from' => 'abc']], [3, 2, 1, 0], []],
            '文字列の不正な UTF-8 は指定なし' => [['title' => "\xFF"], [3, 2, 1, 0], []],
            '複数は AND' => [['title' => 'portal', 'status' => 'done'], [0], ['title' => 'portal', 'status' => 'done']],
        ];
    }

    /**
     * 並び → レコードの順(rows() の添字)と、解釈した並び。
     *
     * @return array<string, array{mixed, list<int>, string}>
     */
    public static function sorts(): array
    {
        return [
            '既定は id の降順' => [null, [3, 2, 1, 0], '-id'],
            'id の昇順' => ['id', [0, 1, 2, 3], 'id'],
            '整数の昇順(未設定は最後)' => ['budget', [0, 1, 2, 3], 'budget'],
            '小数の降順(未設定は最後)' => ['-rate', [0, 1, 3, 2], '-rate'],
            '文字列の昇順' => ['title', [2, 3, 0, 1], 'title'],
            '選択は選択肢の並び(同じ値は id の降順)' => ['status', [3, 2, 0, 1], 'status'],
            '真偽の降順(キーの無いものは偽)' => ['-urgent', [1, 3, 2, 0], '-urgent'],
            '日付の昇順(未設定は最後)' => ['due', [1, 2, 0, 3], 'due'],
            '日付の降順(未設定は最後)' => ['-due', [0, 2, 1, 3], '-due'],
            '日時の昇順(未設定は最後)' => ['starts_at', [2, 0, 1, 3], 'starts_at'],
            '日時の降順(未設定は最後)' => ['-starts_at', [1, 0, 2, 3], '-starts_at'],
            '更新日時の昇順' => ['updated_at', [0, 3, 1, 2], 'updated_at'],
            '更新日時の降順' => ['-updated_at', [2, 1, 3, 0], '-updated_at'],
            '一覧に出さない項目は既定' => ['note', [3, 2, 1, 0], '-id'],
            '形の違う並びは既定' => ['--id', [3, 2, 1, 0], '-id'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->module = $this->recordModule('projects');
        $listed = ['is_listed' => true, 'is_searchable' => true];
        $this->fields = [
            'title' => $this->field($this->module, 'title', attributes: $listed),
            'budget' => $this->field($this->module, 'budget', RecordFieldType::Integer, $listed),
            'rate' => $this->field($this->module, 'rate', RecordFieldType::Decimal, $listed),
            'due' => $this->field($this->module, 'due', RecordFieldType::Date, $listed),
            'starts_at' => $this->field($this->module, 'starts_at', RecordFieldType::Datetime, $listed),
            'urgent' => $this->field($this->module, 'urgent', RecordFieldType::Boolean, $listed),
            'status' => $this->choiceField($this->module, 'status', ['hold', 'done', 'open'], $listed),
            'note' => $this->field($this->module, 'note'),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<int>  $expected
     * @param  array<string, mixed>  $interpreted
     */
    #[DataProvider('filters')]
    public function test_filters_by_the_searchable_fields(array $filters, array $expected, array $interpreted): void
    {
        $ids = $this->rows();

        $listed = $this->list($filters);

        $this->assertSame(array_map(fn (int $index) => $ids[$index], $expected), array_column($listed['records']['data'], 'id'));
        $this->assertSame($interpreted, $listed['query']['filters']);
    }

    /**
     * @param  list<int>  $expected
     */
    #[DataProvider('sorts')]
    public function test_sorts_by_a_listed_field_with_missing_values_last_and_ties_by_id_descending(mixed $sort, array $expected, string $interpreted): void
    {
        $ids = $this->rows();

        $listed = $this->list([], $sort);

        $this->assertSame(array_map(fn (int $index) => $ids[$index], $expected), array_column($listed['records']['data'], 'id'));
        $this->assertSame($interpreted, $listed['query']['sort']);
    }

    public function test_filters_by_a_reference(): void
    {
        $owner = $this->field($this->module, 'owner', RecordFieldType::User, ['is_searchable' => true]);
        $user = $this->user();
        $ids = $this->rows();
        RecordReference::factory()->create(['record_module_id' => $this->module->id, 'record_id' => $ids[1], 'record_field_id' => $owner->id, 'user_id' => $user->id]);

        $this->assertSame([$ids[1]], array_column($this->list(['owner' => (string) $user->id])['records']['data'], 'id'));
    }

    public function test_returns_only_the_listed_values_and_pages_by_20(): void
    {
        $records = Record::factory()->count(25)->create(['record_module_id' => $this->module->id, 'field_values' => [$this->fields['title']->id => 'a', $this->fields['note']->id => 'n']]);
        Record::factory()->detail()->create();

        $second = $this->list([], null, '2');
        $invalidPage = $this->list([], null, 'x');

        $this->assertSame([2, 2, 25], [$second['records']['currentPage'], $second['records']['lastPage'], $second['records']['total']]);
        $this->assertSame(array_reverse(array_slice($records->modelKeys(), 0, 5)), array_column($second['records']['data'], 'id'));
        $this->assertSame(['title', 'budget', 'rate', 'due', 'starts_at', 'urgent', 'status'], array_keys($second['records']['data'][0]['values']));
        $this->assertSame(1, $invalidPage['records']['currentPage']);
    }

    public function test_requires_read_on_the_module(): void
    {
        $this->recordModule('secrets', [RecordModuleAction::Create]);

        $this->expectException(AuthorizationException::class);

        app(RecordPageQuery::class)->open(new Requester(null, null), 'secrets', CarbonImmutable::parse('2026-10-11'), RecordModuleAction::Read);
    }

    /**
     * 一覧の 4 件(作った順 = id の昇順)。未設定の値はキーを持たない。項目の値で並べることと、並びの列の取り違えを見分けるため、
     * 日付・日時・更新日時・id の順(昇順も降順も)は互いに違える。
     *
     * @return list<int>
     */
    private function rows(): array
    {
        $rows = [
            ['title' => 'portal 刷新', 'budget' => 100, 'rate' => '1.5', 'due' => '2026-10-03', 'starts_at' => '2026-10-11T00:59:59Z', 'status' => 'done', 'urgent' => false],
            ['title' => '社内portal', 'budget' => 150, 'rate' => '1.25', 'due' => '2026-10-01', 'starts_at' => '2026-10-11T01:00:00Z', 'urgent' => true, 'status' => 'open'],
            ['title' => '100%達成', 'budget' => 200, 'due' => '2026-10-02', 'starts_at' => '2026-10-11T00:00:00Z', 'status' => 'hold'],
            ['title' => 'a_b', 'budget' => 300, 'status' => 'hold'],
        ];
        $updatedAt = ['2026-10-01 00:00:00', '2026-10-03 00:00:00', '2026-10-04 00:00:00', '2026-10-02 00:00:00'];

        return array_map(fn (array $values, string $updated) => Record::factory()->create([
            'record_module_id' => $this->module->id,
            'field_values' => array_combine(array_map(fn (string $code) => $this->fields[$code]->id, array_keys($values)), array_values($values)),
            'updated_at' => $updated,
        ])->id, $rows, $updatedAt);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{records: array{data: list<array{id: int, values: array<string, mixed>, updatedAt: string|null}>, currentPage: int, lastPage: int, total: int}, query: array{filters: array<string, mixed>, sort: string}}
     */
    private function list(array $filters, mixed $sort = null, mixed $page = null): array
    {
        $requester = new Requester(null, null);
        $date = CarbonImmutable::parse('2026-10-11');
        $opened = app(RecordPageQuery::class)->open($requester, 'projects', $date, RecordModuleAction::Read);

        return app(RecordListQuery::class)->list($requester, $opened, $filters, $sort, $page, $date, 'ja');
    }
}
