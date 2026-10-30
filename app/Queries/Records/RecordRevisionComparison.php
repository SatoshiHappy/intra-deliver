<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;

/**
 * 更新履歴の版の写し 2 つ(snapshot と labels)を比べる 1 か所(規則 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.2 節)。版を作らない判定(同じ文書の 1 節)と差分の表示
 * ({@see RecordRevisionDiff})が同じ規則で決まるように分けた。形の違う写しの値は例外にせず無い値として読む。
 *
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 * @phpstan-import-type FieldEntry from RecordRevisionLabels
 *
 * @phpstan-type RevisionCopy array{snapshot: array<array-key, mixed>, labels: array<array-key, mixed>}
 * @phpstan-type RawRow array<int, mixed>
 * @phpstan-type RawRowChange array{change: 'added'|'removed'|'changed', rowId: int, before: RawRow|null,
 *     after: RawRow|null, changedIds: list<int>}
 * @phpstan-type RawAttachmentChange array{change: 'added'|'removed', attachment: array<array-key, mixed>}
 * @phpstan-type Changes array{fields: list<array{field: FieldEntry, before: mixed, after: mixed}>,
 *     details: list<array{field: FieldEntry, entries: list<FieldEntry>, rows: list<RawRowChange>}>,
 *     attachments: list<RawAttachmentChange>}
 */
class RecordRevisionComparison
{
    public function __construct(private RecordRevisionLabels $labels) {}

    /**
     * @param  RevisionCopy  $before
     * @param  RevisionCopy  $after
     * @return Changes
     */
    public function compare(array $before, array $after): array
    {
        $entries = $this->labels->entries($after['labels']['fields'] ?? []);
        $isValue = fn (array $entry) => $entry['type'] !== RecordFieldType::Details;
        $values = array_values(array_filter($entries, $isValue));
        $rawBefore = $this->rawRow($values, $before['snapshot']);
        $rawAfter = $this->rawRow($values, $after['snapshot']);
        $fields = [];

        foreach ($this->changedEntries($values, $rawBefore, $rawAfter) as $entry) {
            $fields[] = ['field' => $entry, 'before' => $rawBefore[$entry['id']], 'after' => $rawAfter[$entry['id']]];
        }

        return [
            'fields' => $fields,
            'details' => $this->details($entries, $before['snapshot'], $after['snapshot']),
            'attachments' => $this->attachments($before['snapshot'], $after['snapshot']),
        ];
    }

    /**
     * @param  Changes  $changes
     */
    public function isEmpty(array $changes): bool
    {
        return $changes['fields'] === [] && $changes['details'] === [] && $changes['attachments'] === [];
    }

    /**
     * 写しの 1 行(values・references)を、項目の id → 読んだ値にする(通常の項目 = 保存された値の読み方、参照 = 相手の並び)。
     *
     * @param  list<FieldEntry>  $entries  通常・参照の項目
     * @return RawRow
     */
    private function rawRow(array $entries, mixed $part): array
    {
        $values = $this->mapIn($part, 'values');
        $references = $this->mapIn($part, 'references');
        $raw = [];

        foreach ($entries as $entry) {
            $raw[$entry['id']] = match (true) {
                $entry['type']->isReference() => $this->targets($entry, $references[$entry['id']] ?? null),
                $entry['type'] === RecordFieldType::Boolean => (bool) ($values[$entry['id']] ?? false),
                default => $values[$entry['id']] ?? null,
            };
        }

        return $raw;
    }

    /**
     * 参照の値の相手の並び(複数選べる参照は配列の並びのまま。種類が項目の型の相手の種類でない・id が整数でない相手は除く)。
     *
     * @param  FieldEntry  $entry
     * @return list<ReferenceTarget>
     */
    private function targets(array $entry, mixed $value): array
    {
        $kinds = self::referenceTargetKinds($entry['type']);
        $found = [];

        foreach ($this->targetList($entry, $value) as $target) {
            $type = is_array($target) && is_string($target['type'] ?? null) ? $target['type'] : null;

            if (is_array($target) && in_array($type, $kinds, true) && is_int($target['id'] ?? null)) {
                $found[] = ['type' => (string) $type, 'id' => $target['id']];
            }
        }

        return $found;
    }

    /**
     * 項目の型の値が持つ相手の種類(型「ユーザーと組織」はユーザーと組織、ほかの参照の型はその型、参照でない型は無し)。
     * 既定の枝に落とさず全部の型を挙げる(新しい型を足したら静的解析が漏れを知らせる)。
     *
     * @return list<string>
     */
    private static function referenceTargetKinds(RecordFieldType $type): array
    {
        return match ($type) {
            RecordFieldType::User, RecordFieldType::Department, RecordFieldType::Record => [$type->value],
            RecordFieldType::UserOrDepartment => [RecordFieldType::User->value, RecordFieldType::Department->value],
            RecordFieldType::String,
            RecordFieldType::Integer,
            RecordFieldType::Decimal,
            RecordFieldType::Date,
            RecordFieldType::Datetime,
            RecordFieldType::Boolean,
            RecordFieldType::Choice,
            RecordFieldType::Details => [],
        };
    }

    /**
     * 参照の値を相手の候補の並びにする(複数選べる参照は配列のときだけその並び、ほかは値 1 つ)。
     *
     * @param  FieldEntry  $entry
     * @return array<array-key, mixed>
     */
    private function targetList(array $entry, mixed $value): array
    {
        if (! $entry['multiple']) {
            return [$value];
        }

        return is_array($value) && array_is_list($value) ? $value : [];
    }

    /**
     * 値が違う項目(項目の並び。参照は相手の集合 = 種類と id で比べる。型「ユーザーと組織」で id が同じでも種類が違えば別の相手)。
     *
     * @param  list<FieldEntry>  $entries
     * @param  RawRow  $before
     * @param  RawRow  $after
     * @return list<FieldEntry>
     */
    private function changedEntries(array $entries, array $before, array $after): array
    {
        $comparable = function (array $entry, mixed $raw): mixed {
            if (! $entry['type']->isReference() || ! is_array($raw)) {
                return $raw;
            }

            // 相手({type, id}。targets() がキーの順をそろえた写し)を文字列にして、並びによらない集合で比べる
            $keys = array_map(fn (mixed $target) => serialize($target), $raw);
            sort($keys);

            return $keys;
        };

        return array_values(array_filter(
            $entries,
            fn (array $entry) => $comparable($entry, $before[$entry['id']])
                !== $comparable($entry, $after[$entry['id']]),
        ));
    }

    /**
     * @param  list<FieldEntry>  $entries  後の版の全部の項目
     * @return list<array{field: FieldEntry, entries: list<FieldEntry>, rows: list<RawRowChange>}>
     */
    private function details(array $entries, mixed $before, mixed $after): array
    {
        $details = [];

        foreach (array_filter($entries, fn (array $entry) => $entry['type'] === RecordFieldType::Details) as $entry) {
            $children = $this->labels->entries($entry['children']);
            $rowsBefore = $this->rows($children, $this->mapIn($before, 'details')[$entry['id']] ?? null);
            $rowsAfter = $this->rows($children, $this->mapIn($after, 'details')[$entry['id']] ?? null);
            $rows = $this->rowChanges($children, $rowsBefore, $rowsAfter);

            if ($rows !== []) {
                $details[] = ['field' => $entry, 'entries' => $children, 'rows' => $rows];
            }
        }

        return $details;
    }

    /**
     * 写しの明細の項目 1 つの行を、行の id → 読んだ値にする(写しの並び。id が整数でない行は除く)。
     *
     * @param  list<FieldEntry>  $children
     * @return array<int, RawRow>
     */
    private function rows(array $children, mixed $rows): array
    {
        $raw = [];

        foreach (is_array($rows) ? $rows : [] as $row) {
            if (is_array($row) && is_int($row['id'] ?? null)) {
                $raw[$row['id']] = $this->rawRow($children, $row);
            }
        }

        return $raw;
    }

    /**
     * 明細の項目 1 つの行の変化(追加 = 後だけ・削除 = 前だけ・変更 = 両方にあり項目のどれかが違う。それぞれ写しの並び)。
     *
     * @param  list<FieldEntry>  $children
     * @param  array<int, RawRow>  $before
     * @param  array<int, RawRow>  $after
     * @return list<RawRowChange>
     */
    private function rowChanges(array $children, array $before, array $after): array
    {
        $changes = [];

        foreach (array_diff_key($after, $before) as $id => $row) {
            $changes[] = ['change' => 'added', 'rowId' => $id, 'before' => null, 'after' => $row, 'changedIds' => []];
        }

        foreach (array_diff_key($before, $after) as $id => $row) {
            $changes[] = ['change' => 'removed', 'rowId' => $id, 'before' => $row, 'after' => null, 'changedIds' => []];
        }

        foreach (array_intersect_key($after, $before) as $id => $row) {
            $changed = array_column($this->changedEntries($children, $before[$id], $row), 'id');

            if ($changed !== []) {
                $changes[] = ['change' => 'changed', 'rowId' => $id, 'before' => $before[$id], 'after' => $row,
                    'changedIds' => $changed];
            }
        }

        return $changes;
    }

    /**
     * @return list<RawAttachmentChange>
     */
    private function attachments(mixed $before, mixed $after): array
    {
        $byId = function (mixed $snapshot): array {
            $found = [];

            foreach ($this->mapIn($snapshot, 'attachments') as $attachment) {
                if (is_array($attachment) && is_int($attachment['id'] ?? null)) {
                    $found[$attachment['id']] = $attachment;
                }
            }

            return $found;
        };
        [$beforeById, $afterById] = [$byId($before), $byId($after)];
        $changes = [];

        foreach (array_diff_key($afterById, $beforeById) as $attachment) {
            $changes[] = ['change' => 'added', 'attachment' => $attachment];
        }

        foreach (array_diff_key($beforeById, $afterById) as $attachment) {
            $changes[] = ['change' => 'removed', 'attachment' => $attachment];
        }

        return $changes;
    }

    /**
     * 写しの中の配列の位置(無い・配列でないときは空)。
     *
     * @return array<array-key, mixed>
     */
    private function mapIn(mixed $part, string $key): array
    {
        return is_array($part) && is_array($part[$key] ?? null) ? $part[$key] : [];
    }
}
