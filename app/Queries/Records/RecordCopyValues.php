<?php

namespace App\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use DateTimeInterface;
use Illuminate\Support\Collection;
use LogicException;

/**
 * 写しの初期値の内部の 1 か所(docs の 設計/業務データの入れ物/レコードの共通機能/複製.md の 2 節の「内部の写しの口」)。
 * 写す元の種類で入口を分け(レコードから・版から・値から)、
 * 確かめは {@see RecordCopyCheck} に寄せる(保存と写しが同じ 1 か所を呼ぶため)。
 *
 * @phpstan-import-type RawValues from RecordValueReader
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 * @phpstan-import-type ReferenceValue from RecordValueReader
 * @phpstan-import-type StoredRowAccess from RecordRowAccessValues
 * @phpstan-import-type CopySource from RecordCopySources
 * @phpstan-import-type ClearedField from RecordFormQuery
 *
 * @phpstan-type CopiedValues array{values: array<string, mixed>,
 *     details: array<string, list<array{values: array<string, mixed>}>>, clearedFields: list<ClearedField>,
 *     rowAccess: StoredRowAccess|null}
 */
class RecordCopyValues
{
    /** 参照者を外したときに空にした欄の並びの最後に置く名前(複製.md の 2 節の「空にしたものの並べ方」) */
    public const VIEWERS_NAME = '参照制限の参照者';

    public function __construct(
        private RecordCopySources $sources,
        private RecordCopyCheck $checks,
        private RecordValueReader $reader,
        private RecordHeadingGroups $headings,
    ) {}

    /**
     * レコードから(業務データの画面の複製だけが使う。参照制限はレコードの今の値から外した後)。
     *
     * @param  RecordModule  $module  レコードの業務モジュール({@see RecordDefinitionQuery::load()} で読み込んだもの)
     * @return CopiedValues
     */
    public function fromRecord(
        Requester $requester,
        RecordModule $module,
        Record $record,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        return $this->copied($requester, $module, $this->sources->ofRecord($module, $record), null, $date, $localeCode);
    }

    /**
     * 版から(参照制限は版の記録から外した後。記録の無い版は null)。
     *
     * @param  RecordModule  $module  写す先の業務モジュール({@see RecordDefinitionQuery::load()} で読み込んだもの)
     * @return CopiedValues
     *
     * @throws LogicException 版が写す先の業務モジュールのものでないとき(呼び手が業務モジュールの一致を先に確かめる前提 = 作りの誤り)
     */
    public function fromRevision(
        Requester $requester,
        RecordModule $module,
        RecordRevision $revision,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        if ($revision->record_module_id !== $module->id) {
            throw new LogicException("版 {$revision->id} は業務モジュール {$module->code} のものでない");
        }

        $source = $this->sources->ofRevision($module, $revision);

        return $this->copied($requester, $module, $source, null, $date, $localeCode);
    }

    /**
     * 値から(画面の初期値の形の値と明細。参照者を受けず、参照制限を返さない = rowAccess は null)。写す先のレコードがあれば、親の値はそれを指す相手を、
     * 明細の行の値は親であるそれを指す相手を空にする(自分自身と親を指せない規則)。
     *
     * @param  RecordModule  $module  写す先の業務モジュール({@see RecordDefinitionQuery::load()} で読み込んだもの)
     * @param  int|null  $targetRecordId  写す先のレコードの id(指せない相手として比べるだけで読まない)
     * @param  array<array-key, mixed>  $values
     * @param  array<array-key, mixed>  $details
     * @return CopiedValues
     */
    public function fromValues(
        Requester $requester,
        RecordModule $module,
        ?int $targetRecordId,
        array $values,
        array $details,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $source = $this->sources->ofValues($module, $values, $details);

        return $this->copied($requester, $module, $source, $targetRecordId, $date, $localeCode);
    }

    /**
     * @param  CopySource  $source
     * @return CopiedValues
     */
    private function copied(
        Requester $requester,
        RecordModule $module,
        array $source,
        ?int $selfId,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $check = new ReferenceCheck($requester, $date, locksTargets: false);
        $this->checks->prepare($check, $source);
        [$raw, $clearedCodes] = $this->checks->kept($source['fields'], $source['raw'], $check, $selfId, null);
        $rows = [];
        $clearedRows = [];

        foreach ($source['details'] as $code => $detail) {
            foreach ($detail['rows'] as $index => $row) {
                [$rows[$code][], $clearedRows[$code][$index]] = $this->checks
                    ->kept($detail['fields'], $row, $check, null, $selfId);
            }
        }

        [$rowAccess, $viewersCleared] = $this->checks->keptRowAccess($source['rowAccess'], $check);
        $labels = $this->labels($raw, $rows, $requester, $date, $localeCode);
        $details = [];

        foreach ($source['details'] as $code => $detail) {
            $details[$code] = array_map(
                fn (array $row) => ['values' => $this->reader->shown($detail['fields'], $row, $labels)],
                $rows[$code] ?? [],
            );
        }

        return [
            'values' => $this->reader->shown($source['fields'], $raw, $labels),
            'details' => $details,
            'clearedFields' => [
                ...$this->clearedFieldsOf($module, $source, $clearedCodes, $clearedRows),
                ...($viewersCleared ? [self::cleared('viewers', null, null, null, self::VIEWERS_NAME)] : []),
            ],
            'rowAccess' => $rowAccess,
        ];
    }

    /**
     * 残した相手の表示名(判定を求める者で出し分ける = 編集の画面の初期値と同じ)。
     *
     * @param  RawValues  $raw
     * @param  array<string, list<RawValues>>  $rows
     * @return array<string, ReferenceValue>
     */
    private function labels(
        array $raw,
        array $rows,
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $targets = array_merge([], ...array_values($raw['references']));

        foreach ($rows as $detailRows) {
            foreach ($detailRows as $row) {
                array_push($targets, ...array_merge([], ...array_values($row['references'])));
            }
        }

        return $this->reader->targetValues($targets, $requester, $date, $localeCode);
    }

    /**
     * 空にした欄を画面の欄の順で(見出しのまとまりの項目 → 明細の項目ごとに行の順・行の中は項目の順)。画面が空にした欄そのものに印を付けるため、
     * 明細は行の添字まで持つ(名前の明細の行の項目は「{明細の項目の名前}の{項目の名前}」)。
     *
     * @param  CopySource  $source
     * @param  list<string>  $clearedCodes
     * @param  array<string, array<int, list<string>>>  $clearedRows  明細の項目のコード → 行の添字 → 空にした行の項目のコード
     * @return list<ClearedField>
     */
    private function clearedFieldsOf(
        RecordModule $module,
        array $source,
        array $clearedCodes,
        array $clearedRows,
    ): array {
        $order = $clearedCodes === []
            ? []
            : array_merge([], ...array_column($this->headings->groups($module->id), 'fieldCodes'));
        $nameOf = fn (string $code) => $source['fields']->firstWhere('code', $code)?->name;
        $cleared = array_map(
            fn (string $code) => self::cleared('field', $code, null, null, $nameOf($code)),
            array_values(array_filter($order, fn (string $code) => in_array($code, $clearedCodes, true))),
        );

        foreach ($source['details'] as $code => $detail) {
            foreach ($clearedRows[$code] ?? [] as $index => $codes) {
                $cells = $this->clearedCellsOf($code, $detail['field'], $detail['fields'], $index, $codes);
                array_push($cleared, ...$cells);
            }
        }

        return $cleared;
    }

    /**
     * 明細の 1 行の空にしたセル(行の中は項目の順)。
     *
     * @param  Collection<int, RecordField>  $fields  明細の項目
     * @param  list<string>  $codes  空にした行の項目のコード
     * @return list<ClearedField>
     */
    private function clearedCellsOf(
        string $detailCode,
        RecordField $detail,
        Collection $fields,
        int $row,
        array $codes,
    ): array {
        $cells = [];

        foreach ($fields as $field) {
            if (in_array($field->code, $codes, true)) {
                $cells[] = self::cleared('cell', $field->code, $detailCode, $row, "{$detail->name}の{$field->name}");
            }
        }

        return $cells;
    }

    /**
     * @param  'field'|'cell'|'viewers'  $kind
     * @return ClearedField
     */
    private static function cleared(
        string $kind,
        ?string $field,
        ?string $detail,
        ?int $row,
        ?string $name,
    ): array {
        return ['kind' => $kind, 'field' => $field, 'detail' => $detail, 'row' => $row, 'name' => (string) $name];
    }
}
