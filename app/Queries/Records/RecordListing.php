<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordListQuery;
use App\Support\PageNumbers;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 業務の一覧(docs の 設計/業務データの入れ物/項目と規則/README.md 7 節、形は 画面/業務の画面.md 2.1)。画面と公開の口
 * ({@see RecordListQuery})が同じ結果になるよう、数えて並べる所を 1 か所にする。read の判定は呼び手が先に行う(認可とメニュー/README.md 2.2)。
 * 行の判定({@see RecordRowAccess})は数える前に掛ける(件数からも見えないレコードが漏れない = 認可とメニュー/行の権限.md 2.4)。
 *
 * @phpstan-type ListedRecords array{data: list<array{id: int, values: array<string, mixed>, updatedAt: string|null}>,
 *     currentPage: int, lastPage: int, total: int}
 * @phpstan-type Listed array{records: ListedRecords, query: array{filters: array<string, mixed>, sort: string}}
 */
class RecordListing
{
    /** 画面の 1 ページの件数 */
    public const PER_PAGE = 20;

    /** 既定の並び(新しい順) */
    public const DEFAULT_SORT = '-id';

    public function __construct(
        private RecordFilters $filters,
        private RecordValueReader $reader,
        private RecordRowAccess $rows,
    ) {}

    /**
     * 画面の一覧(クエリの文字列を読む所)。
     *
     * @param  mixed  $filters  クエリの filter(項目のコード → 値)
     * @param  mixed  $sort  クエリの sort
     * @param  mixed  $page  クエリの page
     * @param  RecordModule  $module  read の判定を通して開いた業務モジュール({@see RecordPageQuery::open()}。定義を読み込んである)
     * @return Listed
     */
    public function list(
        Requester $requester,
        RecordModule $module,
        mixed $filters,
        mixed $sort,
        mixed $page,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $page = PageNumbers::requested($page);
        $fields = $this->listedFields($module);

        return $this->listed($requester, $module, $filters, $sort, $page, self::PER_PAGE, $fields, $date, $localeCode);
    }

    /**
     * 返す項目の既定(一覧の項目)。
     *
     * @param  RecordModule  $module  定義を読み込んだ業務モジュール
     * @return Collection<int, RecordField>
     */
    public function listedFields(RecordModule $module): Collection
    {
        return $module->recordFields->where('is_listed', true)->values();
    }

    /**
     * 解釈した値で数えて並べる所(画面と公開の口が共有する。保証 = docs の 設計/ワークフロー/業務データとの接続/公開の口/表示と関係の口.md の
     * 7.3 の RecordListQuery の行)。
     *
     * @param  mixed  $filters  条件(項目のコード → 値。画面のクエリの filter と同じ形)
     * @param  mixed  $sort  並び(画面のクエリの sort と同じ文字列。読めなければ既定)
     * @param  int  $page  引きたいページ(最後のページに詰める。1 未満は 1)
     * @param  int<1, max>  $perPage
     * @param  Collection<int, RecordField>  $fields  返す項目(明細を除く。項目の並び。選択肢を読み込んでおく)
     * @return Listed
     */
    public function listed(
        Requester $requester,
        RecordModule $module,
        mixed $filters,
        mixed $sort,
        int $page,
        int $perPage,
        Collection $fields,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $query = Record::query()->where('record_module_id', $module->id);
        $query = $this->rows->constrain($query, $requester, $module, $date);
        $searchable = $module->recordFields->where('is_searchable', true);
        $appliedFilters = $this->filters->apply($query, $searchable, $filters);
        $appliedSort = $this->sort($query, $fields, $sort);
        $total = $query->toBase()->getCountForPagination();
        $pageNumber = PageNumbers::clamp($page, $total, $perPage);
        $paginator = $query->paginate(perPage: $perPage, page: $pageNumber, total: $total);
        $records = new Collection($paginator->items());
        $values = $this->reader->values($records, $fields, $requester, $date, $localeCode);

        return [
            'records' => [
                'data' => array_values($records->map(fn (Record $record) => [
                    'id' => $record->id,
                    'values' => $values[$record->id] ?? [],
                    'updatedAt' => $record->updated_at?->toIso8601String(),
                ])->all()),
                'currentPage' => $paginator->currentPage(),
                'lastPage' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
            'query' => ['filters' => $appliedFilters, 'sort' => $appliedSort],
        ];
    }

    /**
     * 並びを掛け、解釈した並びを返す(並べられるのは返す項目のうち素の型。画面は返す項目 = 一覧の項目なので、画面の規則と同じ)。
     *
     * @param  Builder<Record>  $query
     * @param  Collection<int, RecordField>  $fields  返す項目
     */
    private function sort(Builder $query, Collection $fields, mixed $sort): string
    {
        if (! is_string($sort) || preg_match('/^(-?)([a-z][a-z0-9_]{0,49})$/D', $sort, $parts) !== 1) {
            $parts = [self::DEFAULT_SORT, '-', 'id'];
        }

        [$text, $sign, $name] = $parts;
        $direction = $sign === '-' ? 'desc' : 'asc';
        $field = $fields->first(fn (RecordField $field) => $field->code === $name && $field->type->isPlain());

        if ($field !== null) {
            $this->orderByField($query, $field, $direction);
        } elseif ($name === 'updated_at') {
            $query->orderByRaw("records.updated_at {$direction} NULLS LAST");
        } elseif ($name !== 'id') {
            return $this->sort($query, $fields, self::DEFAULT_SORT);
        }

        $query->orderBy('records.id', $name === 'id' ? $direction : 'desc');

        return $text;
    }

    /**
     * @param  Builder<Record>  $query
     * @param  'asc'|'desc'  $direction
     */
    private function orderByField(Builder $query, RecordField $field, string $direction): void
    {
        $key = (string) $field->id;

        if ($field->type === RecordFieldType::Choice) {
            $query->orderByRaw(
                '(SELECT o.sort_key FROM record_field_options o'
                ." WHERE o.record_field_id = ? AND o.code = records.field_values ->> ?) {$direction} NULLS LAST",
                [$field->id, $key],
            );
        }

        $expression = match ($field->type) {
            RecordFieldType::Boolean => RecordFilters::BOOLEAN_SQL,
            RecordFieldType::Integer,
            RecordFieldType::Decimal,
            RecordFieldType::Date,
            RecordFieldType::Datetime => '(records.field_values ->> ?)::'.RecordFilters::sqlType($field->type),
            default => '(records.field_values ->> ?)',
        };
        $query->orderByRaw("{$expression} {$direction} NULLS LAST", [$key]);
    }
}
