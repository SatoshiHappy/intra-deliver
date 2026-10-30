<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\Record;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * 一時保存の入力(業務処理の入力 RecordInput と同じ形。項目のコードがキー、検証していない値)を、画面の初期値の形に直す(docs の
 * 設計/ワークフロー/業務データとの接続/申請と書き込み.md 2 節「開き方」)。公開の口 {@see RecordFormQuery::draftValues()} の中身。
 *
 * - 今の定義の項目だけを読み、定義に無いキーと、明細の項目のキーを values に置いたものは捨てる。値は今の定義の項目のすべてに返す(無ければ未設定)。
 * - 通常の項目は型ごとの受け付ける形({@see FieldValueParser::parse()})で解釈・正規化し、通らない値は未設定(真偽は偽 = 未設定を持たない)。
 *   長さ・必須・規則は見ない(申請の時に検証する)。文字列は型の解釈で落ちない(文字列なら長さ・改行を見ずにそのまま。文字列でない・JSON に
 *   表せない文字列(不正な UTF-8・U+0000)は未設定)。
 * - 選択は文字列なら生のコードを保つ(今の選択肢に無いコードは name が null。文字列でなければ未設定)。
 * - 参照は整数のときだけ相手を引く(型「ユーザーと組織」は相手の形 {type, id} のときだけ = {@see RecordParty::of()}。種類を保つ = 写しとつながる申請.md 9 節)。
 *   相手が無ければ id を残して表示名なし。複数選べる参照は配列のときだけ、相手の形の要素だけで相手を引く
 *   (配列でなければ未設定。相手の形でない要素と、同じ相手の 2 度目以降は捨てる。要素が残らなければ未設定)。他モジュール参照の相手が、項目の参照先の業務モジュールの親の無い
 *   レコードでなければ、その項目では相手が無いのと同じ。
 * - 明細の行は values だけで id を持たない(一時保存の行はいつも新しい行)。配列でない行は捨てる。
 *
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 * @phpstan-import-type RawValues from RecordValueReader
 * @phpstan-import-type ReferenceValue from RecordValueReader
 *
 * @phpstan-type DraftValues array{values: array<string, mixed>, details: array<string, list<array{values: array<string, mixed>}>>}
 */
class DraftValueReader
{
    public function __construct(
        private FieldValueParser $parser,
        private RecordValueReader $reader,
        private RecordDefinitionQuery $definitions,
    ) {}

    /**
     * @param  RecordModule  $module  {@see RecordDefinitionQuery::load()} で定義を読み込んだ業務モジュール
     * @param  array<array-key, mixed>  $values  項目のコード → 値
     * @param  array<array-key, mixed>  $details  明細の項目のコード → 行(行 = values を持つ配列)
     * @return DraftValues
     */
    public function read(RecordModule $module, array $values, array $details, Requester $requester, DateTimeInterface $date, string $localeCode): array
    {
        $targets = [];
        $fields = $this->definitions->valueFields($module);
        $raw = $this->rawValues($fields, $values, $targets);
        $rawDetails = [];

        foreach ($this->definitions->detailsFields($module) as $field) {
            if ($field->childRecordModule !== null) {
                $childFields = $this->definitions->valueFields($field->childRecordModule);
                $rows = is_array($details[$field->code] ?? null) ? $details[$field->code] : [];
                $rawDetails[$field->code] = [$childFields, $this->rawRows($childFields, $rows, $targets)];
            }
        }

        $labels = $this->reader->targetValues(array_values($targets), $requester, $date, $localeCode);
        $records = $this->recordTargets($targets);

        return [
            'values' => $this->shown($fields, $raw, $labels, $records),
            'details' => array_map(
                fn (array $detail) => array_map(fn (array $row) => ['values' => $this->shown($detail[0], $row, $labels, $records)], $detail[1]),
                $rawDetails,
            ),
        ];
    }

    /**
     * 他モジュール参照の相手のレコード(id → レコード。項目が指せる相手かを確かめるため)。
     *
     * @param  array<string, ReferenceTarget>  $targets
     * @return \Illuminate\Support\Collection<int, Record>
     */
    private function recordTargets(array $targets): \Illuminate\Support\Collection
    {
        $recordIds = array_column(array_filter($targets, fn (array $target) => $target['type'] === RecordFieldType::Record->value), 'id');

        return Record::query()->whereKey($recordIds)->get(['id', 'record_module_id', 'parent_record_id'])->keyBy('id');
    }

    /**
     * 画面の値の形({@see RecordValueReader::shown()})。他モジュール参照の相手が、その項目の参照先の業務モジュールの親の無いレコードでなければ、
     * その項目では相手が無いのと同じ(id を残して表示名なし = 項目と規則/README.md 5 節の指せる相手)。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  RawValues  $raw
     * @param  array<string, ReferenceValue>  $labels
     * @param  \Illuminate\Support\Collection<int, Record>  $records
     * @return array<string, mixed>
     */
    private function shown(Collection $fields, array $raw, array $labels, \Illuminate\Support\Collection $records): array
    {
        $shown = $this->reader->shown($fields, $raw, $labels);

        foreach ($fields->where('type', RecordFieldType::Record) as $field) {
            $target = $raw['references'][$field->code][0] ?? null;
            $record = $target === null ? null : $records->get($target['id']);

            if ($target !== null && ($record?->record_module_id !== $field->referenced_record_module_id || $record?->parent_record_id !== null)) {
                $shown[$field->code] = ['id' => $target['id'], 'code' => null, 'label' => null, 'affiliation' => null];
            }
        }

        return $shown;
    }

    /**
     * 明細の行(配列の行だけ。行の values が配列でなければ値の無い行)。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  array<array-key, mixed>  $rows
     * @param  array<string, ReferenceTarget>  $targets
     * @return list<RawValues>
     */
    private function rawRows(Collection $fields, array $rows, array &$targets): array
    {
        $raw = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $raw[] = $this->rawValues($fields, is_array($row['values'] ?? null) ? $row['values'] : [], $targets);
            }
        }

        return $raw;
    }

    /**
     * 入力の値を項目ごとに読む。参照の相手を $targets に足す(鍵 = {@see RecordValueReader::targetKey()})。
     *
     * @param  Collection<int, RecordField>  $fields
     * @param  array<array-key, mixed>  $input  項目のコード → 値
     * @param  array<string, ReferenceTarget>  $targets
     * @return RawValues
     */
    private function rawValues(Collection $fields, array $input, array &$targets): array
    {
        return $this->reader->rawValues($fields, fn (RecordField $field) => $this->plainValue($field, $input[$field->code] ?? null), function (RecordField $field) use ($input, &$targets): array {
            $value = $input[$field->code] ?? null;
            $found = $this->referenceTargetsOf($field, $field->is_multiple ? $value : [$value]);

            foreach ($found as $target) {
                $targets[$this->reader->targetKey($target)] = $target;
            }

            return $found;
        });
    }

    /**
     * 参照の入力の相手の並び(1 つの値の項目は値 1 つの並びで渡す。配列のリストでなければ空)。相手の形の要素だけを残し(型「ユーザーと組織」=
     * {@see RecordParty::of()}、ほかの参照の型 = 整数)、同じ相手の 2 度目以降は捨てる(最初の位置を残す。検証しない読み取りなので
     * 重なった値が入りうる)。100 件を超える分は捨てない(申請の時の検証が知らせる)。
     *
     * @return list<ReferenceTarget>
     */
    private function referenceTargetsOf(RecordField $field, mixed $values): array
    {
        $found = [];

        foreach (is_array($values) && array_is_list($values) ? $values : [] as $value) {
            $target = match (true) {
                $field->type->hasPartyKind() => RecordParty::of($value),
                is_int($value) => ['type' => $field->type->value, 'id' => $value],
                default => null,
            };

            if ($target !== null) {
                $found[$this->reader->targetKey($target)] ??= $target;
            }
        }

        return array_values($found);
    }

    /**
     * 通常の項目の値を型の解釈で読む(通らなければ未設定)。読んだ値は保存された値と同じ読み方({@see FieldValueParser::stored()})で返す。
     * 選択は文字列なら生のコード(今の選択肢に無いコードも保つ = 選択肢の誤りは型の解釈に通る側。表示の読み方は shown() が掛ける)。
     */
    private function plainValue(RecordField $field, mixed $value): mixed
    {
        return match ($field->type) {
            // JSON・jsonb に表せない文字列(不正な UTF-8・U+0000)だけを落とす。改行・長さは定義の検証で、申請の時に見る(stringOf とは文言を分けるので共有しない)
            RecordFieldType::String => is_string($value) && mb_check_encoding($value, 'UTF-8') && ! str_contains($value, "\0") ? $value : null,
            RecordFieldType::Choice => is_string($value) && $value !== '' ? $value : null,
            default => $this->parsedValue($field, $value),
        };
    }

    /**
     * 型の解釈({@see FieldValueParser::parse()})で読んだ値(通らなければ未設定)。
     */
    private function parsedValue(RecordField $field, mixed $value): mixed
    {
        try {
            $parsed = $this->parser->parse($field, $value);
        } catch (InvalidFieldValue) {
            $parsed = null;
        }

        return $this->parser->stored($field, $parsed === null ? [] : [$field->id => $parsed]);
    }
}
