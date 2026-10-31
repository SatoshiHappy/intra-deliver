<?php

namespace App\Queries\Records\Api;

use App\Enums\Records\RecordFieldType;
use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordDefinitionQuery;
use App\Queries\Records\RecordHeadingGroups;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordQuery;
use App\Queries\Records\RecordRelatedColumns;
use App\Queries\Records\RecordRelatedEntries;
use App\Queries\Records\RecordRowAccess;
use App\Queries\Records\RecordRowAccessValues;
use App\Queries\Records\RecordValueReader;
use App\Support\PageNumbers;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * 関連レコードの読み取り(docs の 設計/業務データの入れ物/レコードの共通機能/関連レコード.md の 7 節)。レコードの詳細と申請の詳細が同じ形で使う。
 * このレコードの閲覧は呼び手が判定済み(閲覧できない人には entryNames を使う)。入口は参照元の業務モジュールの read で絞り
 * (読めない入口は名前も件数も返さない = 3 節)、参照元は行の判定({@see RecordRowAccess})の条件を足した 1 本の問い合わせで数えて並べる
 * (件数・表・ページとも同じ範囲。参照元の数で問い合わせが増えない)。明細の行から指す参照元は親のレコードとして 1 件に数える。
 *
 * @phpstan-import-type NamedEntry from RecordRelatedEntries
 * @phpstan-import-type FieldDefinition from RecordDefinitionQuery
 * @phpstan-import-type ModuleDefinition from RecordDefinitionQuery
 * @phpstan-import-type HeadingGroup from RecordHeadingGroups
 * @phpstan-import-type ReferenceValue from RecordValueReader
 *
 * @phpstan-type RelatedRows array{entry: string, module: array{code: string, name: string},
 *     columns: list<FieldDefinition>, rows: list<array{id: int, values: array<string, mixed>, updatedAt: string|null}>,
 *     currentPage: int, lastPage: int, total: int}
 * @phpstan-type RelatedForm array{entry: string, field: string, value: ReferenceValue, module: ModuleDefinition,
 *     headings: list<HeadingGroup>, rowAccess: array{restricted: bool, viewers: list<never>}}
 */
class RecordRelatedQuery
{
    /** 表の 1 ページの件数 */
    public const PER_PAGE = 20;

    public function __construct(
        private RecordQuery $records,
        private RecordRelatedEntries $entries,
        private RecordRelatedColumns $columns,
        private RecordModuleGate $gate,
        private RecordRowAccess $rows,
        private RecordDefinitionQuery $definitions,
        private RecordValueReader $reader,
        private RecordHeadingGroups $headings,
        private RecordRowAccessValues $rowAccess,
    ) {}

    /**
     * 見える入口(入口の並び)と、入口ごとの見える参照元の件数・「新規」を出せるか。
     *
     * @return list<RecordRelatedEntry>
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function entries(Requester $requester, int $recordId, DateTimeInterface $date): array
    {
        $record = $this->records->topLevel($recordId);
        // このレコードの側の条件と業務モジュールごとの create は、1 回の呼び出しの中で 1 回だけ判定する
        [$ownSide, $createAllowed] = [null, []];

        return array_map(function (array $entry) use ($requester, $record, $date, &$ownSide, &$createAllowed) {
            $creatable = $this->creatableEntry($requester, $entry, $date, $createAllowed)
                && ($ownSide ??= $this->ownSideAllows($requester, $record, $date));

            return new RecordRelatedEntry(
                $entry['key'],
                $entry['name'],
                $this->referencing($requester, $entry, $record->id, $date)->count(),
                $creatable,
            );
        }, $this->visible($requester, $record, $date));
    }

    /**
     * 見える入口のキーと名前(件数と「新規」を持たない。このレコードの閲覧に依らない)。
     *
     * @return list<array{key: string, name: string}>
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function entryNames(Requester $requester, int $recordId, DateTimeInterface $date): array
    {
        return array_map(
            fn (array $entry) => ['key' => $entry['key'], 'name' => $entry['name']],
            $this->visible($requester, $this->records->topLevel($recordId), $date),
        );
    }

    /**
     * 入口の表の 1 ページ(更新日時の降順、次に id の降順。最後のページを超える $page は最後のページに詰める)。列 = 選んだ列か未選択の列(更新日は画面が足す)、値は画面の値の形。
     *
     * @return RelatedRows
     *
     * @throws ModelNotFoundException<Record|RecordField> レコードが無い・明細の行のとき、入口が無い・見えないとき(存在を見せない)
     */
    public function rows(
        Requester $requester,
        int $recordId,
        string $entryKey,
        int $page,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $record = $this->records->topLevel($recordId);
        $entry = $this->visibleEntry($requester, $record, $entryKey, $date)
            ?? throw (new ModelNotFoundException)->setModel(RecordField::class);
        $module = $this->definitions->load($entry['module']);
        $columns = $this->columns->shown($entry['field'], $module);
        $query = $this->referencing($requester, $entry, $record->id, $date)
            ->orderByRaw('records.updated_at DESC NULLS LAST')->orderByDesc('records.id');
        $total = $query->toBase()->getCountForPagination();
        $paginator = $query->paginate(
            perPage: self::PER_PAGE,
            page: PageNumbers::clamp($page, $total, self::PER_PAGE),
            total: $total,
        );
        $items = new Collection($paginator->items());
        $values = $this->reader->values($items, $columns, $requester, $date, $localeCode);

        return [
            'entry' => $entry['key'],
            'module' => ['code' => $module->code, 'name' => $module->name],
            'columns' => array_values($columns->map(
                fn (RecordField $field) => $this->definitions->fieldDefinition($field),
            )->all()),
            'rows' => array_values($items->map(fn (Record $row) => [
                'id' => $row->id,
                'values' => $values[$row->id] ?? [],
                'updatedAt' => $row->updated_at?->toIso8601String(),
            ])->all()),
            'currentPage' => $paginator->currentPage(),
            'lastPage' => $paginator->lastPage(),
            'total' => $paginator->total(),
        ];
    }

    /**
     * 「新規」のダイアログのフォーム(4.1 節の判定。出せなければ null): 参照元の業務モジュールの登録のフォームの定義と見出しのまとまり、
     * 入口の項目のコードとその初期値(このレコードの表示名つきの参照の値)。
     *
     * @return RelatedForm|null
     *
     * @throws ModelNotFoundException<Record> レコードが無い・明細の行のとき
     */
    public function form(
        Requester $requester,
        int $recordId,
        string $entryKey,
        DateTimeInterface $date,
        string $localeCode,
    ): ?array {
        $record = $this->records->topLevel($recordId);
        $entry = $this->visibleEntry($requester, $record, $entryKey, $date);
        $createAllowed = [];

        if ($entry === null
            || ! $this->creatableEntry($requester, $entry, $date, $createAllowed)
            || ! $this->ownSideAllows($requester, $record, $date)) {
            return null;
        }

        $module = $this->definitions->load($entry['module']);
        $target = ['type' => RecordFieldType::Record->value, 'id' => $record->id];
        $labels = $this->reader->targetValues([$target], $requester, $date, $localeCode);

        return [
            'entry' => $entry['key'],
            'field' => $entry['code'],
            'value' => $labels[$this->reader->targetKey($target)]
                ?? ['id' => $record->id, 'code' => null, 'label' => null, 'affiliation' => null],
            'module' => $this->definitions->definition($module),
            'headings' => $this->headings->groups($module->id),
            'rowAccess' => $this->rowAccess->initial($module),
        ];
    }

    /**
     * 見る人が参照元の業務モジュールの read を許される入口(入口の並び)。
     *
     * @return list<NamedEntry>
     */
    private function visible(Requester $requester, Record $record, DateTimeInterface $date): array
    {
        // 同じ業務モジュールの入口が 2 つ以上あっても、read の判定は業務モジュールごとに 1 回
        $readable = [];

        return array_values(array_filter(
            $this->entries->referencing($record->record_module_id),
            function (array $entry) use ($requester, $date, &$readable): bool {
                return $readable[$entry['module']->id]
                    ??= $this->gate->allows($requester, $entry['module'], RecordModuleAction::Read, $date);
            },
        ));
    }

    /**
     * @return NamedEntry|null
     */
    private function visibleEntry(
        Requester $requester,
        Record $record,
        string $entryKey,
        DateTimeInterface $date,
    ): ?array {
        foreach ($this->visible($requester, $record, $date) as $entry) {
            if ($entry['key'] === $entryKey) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * 入口の参照元(行の業務モジュールの親の無いレコードで、入口の項目(明細の項目は明細の行)がこのレコードを指すもの)に、行の判定の条件を足した問い合わせ。
     *
     * @param  NamedEntry  $entry
     * @return Builder<Record>
     */
    private function referencing(Requester $requester, array $entry, int $recordId, DateTimeInterface $date): Builder
    {
        $pointing = fn (QueryBuilder $references) => $references->from('record_references')
            ->where('record_references.record_field_id', $entry['field']->id)
            ->where('record_references.referenced_record_id', $recordId);
        $query = Record::query()->where('records.record_module_id', $entry['module']->id)->whereExists(
            $entry['detailsField'] === null
                ? fn (QueryBuilder $references) => $pointing($references)
                    ->whereColumn('record_references.record_id', 'records.id')
                : fn (QueryBuilder $references) => $pointing($references)
                    ->join('records as referencing_rows', 'referencing_rows.id', '=', 'record_references.record_id')
                    ->whereColumn('referencing_rows.parent_record_id', 'records.id'),
        );

        return $this->rows->constrain($query, $requester, $entry['module'], $date);
    }

    /**
     * 4.1 節の入口の側の条件: 入口の項目が親の無い業務モジュールの項目で、参照元の業務モジュールの create が許される。
     * create の判定は呼び手の覚え $allowed(業務モジュールの id → 可否)で業務モジュールごとに 1 回。
     *
     * @param  NamedEntry  $entry
     * @param  array<int, bool>  $allowed
     */
    private function creatableEntry(Requester $requester, array $entry, DateTimeInterface $date, array &$allowed): bool
    {
        return $entry['detailsField'] === null
            && ($allowed[$entry['module']->id]
                ??= $this->gate->allows($requester, $entry['module'], RecordModuleAction::Create, $date));
    }

    /**
     * 4.1 節のこのレコードの側の条件: このレコードの業務モジュールの read が許され、このレコードが行の判定を通る。
     */
    private function ownSideAllows(Requester $requester, Record $record, DateTimeInterface $date): bool
    {
        $module = RecordModule::query()->findOrFail($record->record_module_id);

        return $this->gate->allows($requester, $module, RecordModuleAction::Read, $date)
            && $this->rows->allows($requester, $module, $record->id, $date);
    }
}
