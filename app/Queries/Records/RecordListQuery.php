<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Support\PageNumbers;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * 業務の一覧(docs の 設計/業務データの入れ物/項目と規則/README.md 7 節、形は 画面/業務の画面.md 2.1 の records・query)。判定(認可とメニュー/README.md 2.2 の一覧
 * = read)は呼び手が {@see RecordPageQuery::open()} で先に行い、開いた業務モジュールを渡す。検索条件は {@see RecordFilters}。
 * 行の判定({@see RecordRowAccess})の条件を足してから数えて並べる(件数 total とページからも見えないレコードの存在が漏れない = 2.4)。
 */
class RecordListQuery
{
    /** 1 ページの件数 */
    public const PER_PAGE = 20;

    /** 既定の並び(新しい順) */
    public const DEFAULT_SORT = '-id';

    public function __construct(
        private RecordFilters $filters,
        private RecordValueReader $reader,
        private RecordRowAccess $rows,
    ) {}

    /**
     * @param  mixed  $filters  クエリの filter(項目のコード → 値)
     * @param  mixed  $sort  クエリの sort
     * @param  mixed  $page  クエリの page
     * @param  RecordModule  $module  read の判定を通して開いた業務モジュール({@see RecordPageQuery::open()}。定義を読み込んである)
     * @return array{records: array{data: list<array{id: int, values: array<string, mixed>, updatedAt: string|null}>, currentPage: int, lastPage: int, total: int}, query: array{filters: array<string, mixed>, sort: string}}
     */
    public function list(Requester $requester, RecordModule $module, mixed $filters, mixed $sort, mixed $page, DateTimeInterface $date, string $localeCode): array
    {
        $query = Record::query()->where('record_module_id', $module->id);
        $this->rows->constrain($query, $requester, $module, $date);
        $appliedFilters = $this->filters->apply($query, $module->recordFields->where('is_searchable', true), $filters);
        $appliedSort = $this->sort($query, $module, $sort);
        $total = $query->toBase()->getCountForPagination();
        $pageNumber = PageNumbers::of($page, $total, self::PER_PAGE);
        $paginator = $query->paginate(perPage: self::PER_PAGE, page: $pageNumber, total: $total);
        $listed = $module->recordFields->where('is_listed', true)->values();
        $records = new Collection($paginator->items());
        $values = $this->reader->values($records, $listed, $requester, $date, $localeCode);

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
     * 並びを掛け、解釈した並びを返す。
     *
     * @param  Builder<Record>  $query
     */
    private function sort(Builder $query, RecordModule $module, mixed $sort): string
    {
        if (! is_string($sort) || preg_match('/^(-?)([a-z][a-z0-9_]{0,49})$/D', $sort, $parts) !== 1) {
            $parts = [self::DEFAULT_SORT, '-', 'id'];
        }

        [$text, $sign, $name] = $parts;
        $direction = $sign === '-' ? 'desc' : 'asc';
        $field = $module->recordFields->first(fn (RecordField $field) => $field->code === $name && $field->is_listed && $field->type->isPlain());

        if ($field !== null) {
            $this->orderByField($query, $field, $direction);
        } elseif ($name === 'updated_at') {
            $query->orderByRaw("records.updated_at {$direction} NULLS LAST");
        } elseif ($name !== 'id') {
            return $this->sort($query, $module, self::DEFAULT_SORT);
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
                "(SELECT o.sort_key FROM record_field_options o WHERE o.record_field_id = ? AND o.code = records.field_values ->> ?) {$direction} NULLS LAST",
                [$field->id, $key],
            );
        }

        $expression = match ($field->type) {
            RecordFieldType::Boolean => RecordFilters::BOOLEAN_SQL,
            RecordFieldType::Integer, RecordFieldType::Decimal, RecordFieldType::Date, RecordFieldType::Datetime => '(records.field_values ->> ?)::'.RecordFilters::sqlType($field->type),
            default => '(records.field_values ->> ?)',
        };
        $query->orderByRaw("{$expression} {$direction} NULLS LAST", [$key]);
    }
}
