<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use DateTimeInterface;

/**
 * 更新履歴の版の写し 2 つの差分を画面の値で返す 1 か所(docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 5.2 節)。
 * 比べ方は {@see RecordRevisionComparison} に任せ、ここは名前と参照の表示名(設計/業務データの入れ物/認可とメニュー/README.md の
 * 2.4 節)だけを足す。業務モジュールの read は呼び手が判定する。
 *
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 * @phpstan-import-type ReferenceValue from RecordValueReader
 * @phpstan-import-type RevisionCopy from RecordRevisionComparison
 * @phpstan-import-type FieldEntry from RecordRevisionLabels
 * @phpstan-import-type RawRow from RecordRevisionComparison
 * @phpstan-import-type Changes from RecordRevisionComparison
 * @phpstan-import-type RawAttachmentChange from RecordRevisionComparison
 * @phpstan-import-type Diff from RecordRevisionQuery
 */
class RecordRevisionDiff
{
    public function __construct(
        private RecordRevisionComparison $comparison,
        private RecordValueReader $reader,
        private RecordRevisionRowAccess $rowAccess,
    ) {}

    /**
     * @param  RevisionCopy  $before
     * @param  RevisionCopy  $after
     * @return Diff
     */
    public function diff(
        array $before,
        array $after,
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $changes = $this->comparison->compare($before, $after);

        return $this->shown($changes, $before, $after, $requester, $date, $localeCode);
    }

    /**
     * 比べた結果({@see RecordRevisionComparison::compare()})を画面の値にする。
     *
     * @param  Changes  $changes
     * @param  RevisionCopy  $before
     * @param  RevisionCopy  $after
     * @return Diff
     */
    public function shown(
        array $changes,
        array $before,
        array $after,
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $targets = $this->reader->targetValues($this->targetsOf($changes), $requester, $date, $localeCode);
        $beforeOptions = $this->optionsOf($before);
        $afterOptions = $this->optionsOf($after);

        return [
            'fields' => array_map(fn (array $change) => [
                'code' => $change['field']['code'],
                'name' => $change['field']['name'],
                'before' => $this->value($change['field'], $change['before'], $beforeOptions, $targets),
                'after' => $this->value($change['field'], $change['after'], $afterOptions, $targets),
            ], $changes['fields']),
            'details' => array_map(fn (array $detail) => [
                'code' => $detail['field']['code'],
                'name' => $detail['field']['name'],
                'rows' => array_map(fn (array $row) => [
                    'change' => $row['change'],
                    'rowId' => $row['rowId'],
                    'before' => $this->row($detail['entries'], $row['before'], $beforeOptions, $targets),
                    'after' => $this->row($detail['entries'], $row['after'], $afterOptions, $targets),
                    'changedCodes' => $this->codesOf($detail['entries'], $row['changedIds']),
                ], $detail['rows']),
            ], $changes['details']),
            'attachments' => array_map(fn (array $change) => $this->attachment($change), $changes['attachments']),
            'rowAccess' => $this->rowAccess->shown($changes['rowAccess'], $targets),
        ];
    }

    /**
     * 明細の行 1 つの値(項目のコード → 値。行が無ければ null)。
     *
     * @param  list<FieldEntry>  $entries
     * @param  RawRow|null  $raw
     * @param  array<int, array<string, string>>  $options
     * @param  array<string, ReferenceValue>  $targets
     * @return array<string, mixed>|null
     */
    private function row(array $entries, ?array $raw, array $options, array $targets): ?array
    {
        if ($raw === null) {
            return null;
        }

        $shown = [];

        foreach ($entries as $entry) {
            $shown[$entry['code']] = $this->value($entry, $raw[$entry['id']] ?? null, $options, $targets);
        }

        return $shown;
    }

    /**
     * 項目 1 つの画面の値(参照の相手が無ければ null、複数選べる参照は相手ごとの値の配列。選択は {code, name})。
     *
     * @param  FieldEntry  $entry
     * @param  array<int, array<string, string>>  $options  版の選択の項目の id → 選択肢のコード → 名前
     * @param  array<string, ReferenceValue>  $targets  相手の鍵 → 値
     */
    private function value(array $entry, mixed $raw, array $options, array $targets): mixed
    {
        if ($entry['type']->isReference()) {
            return $this->referenceValue($entry, $raw, $targets);
        }

        if ($entry['type'] === RecordFieldType::Choice && is_string($raw)) {
            return ['code' => $raw, 'name' => $options[$entry['id']][$raw] ?? null];
        }

        return $raw;
    }

    /**
     * 参照の項目 1 つの画面の値(今は無い相手は id を残して code・label が null。型「ユーザーと組織」は相手の種類 type を足す =
     * 読み取りと同じ形)。
     *
     * @param  FieldEntry  $entry
     * @param  array<string, ReferenceValue>  $targets
     */
    private function referenceValue(array $entry, mixed $raw, array $targets): mixed
    {
        $shown = array_map(function (array $target) use ($entry, $targets): array {
            $value = $targets[$this->reader->targetKey($target)]
                ?? ['id' => $target['id'], 'code' => null, 'label' => null, 'affiliation' => null];

            return $this->reader->withKind($entry['type'], $target, $value);
        }, $this->targetsIn($raw));

        return match (true) {
            $shown === [] => null,
            $entry['multiple'] => $shown,
            default => $shown[0],
        };
    }

    /**
     * 参照の項目の読んだ値(相手の並び)の相手。
     *
     * @return list<ReferenceTarget>
     */
    private function targetsIn(mixed $raw): array
    {
        $found = [];

        foreach (is_array($raw) ? $raw : [] as $target) {
            if (is_array($target) && is_string($target['type'] ?? null) && is_int($target['id'] ?? null)) {
                $found[] = ['type' => $target['type'], 'id' => $target['id']];
            }
        }

        return $found;
    }

    /**
     * @param  RawAttachmentChange  $change
     * @return array{change: 'added'|'removed', id: int, uploadId: int|null, name: string}
     */
    private function attachment(array $change): array
    {
        $attachment = $change['attachment'];

        return [
            'change' => $change['change'],
            'id' => is_int($attachment['id'] ?? null) ? $attachment['id'] : 0,
            'uploadId' => is_int($attachment['upload_id'] ?? null) ? $attachment['upload_id'] : null,
            'name' => is_string($attachment['name'] ?? null) ? $attachment['name'] : '',
        ];
    }

    /**
     * 変化に出る参照の相手(項目の両側と、明細の行の全部の参照の項目と、参照者の両側)。
     *
     * @param  Changes  $changes
     * @return list<ReferenceTarget>
     */
    private function targetsOf(array $changes): array
    {
        $targets = [];

        foreach ($changes['fields'] as $change) {
            $targets[] = $this->rowTargets([$change['field']], [$change['field']['id'] => $change['before']]);
            $targets[] = $this->rowTargets([$change['field']], [$change['field']['id'] => $change['after']]);
        }

        foreach ($changes['details'] as $detail) {
            foreach ($detail['rows'] as $row) {
                $targets[] = $this->rowTargets($detail['entries'], $row['before'] ?? []);
                $targets[] = $this->rowTargets($detail['entries'], $row['after'] ?? []);
            }
        }

        return array_merge($this->rowAccess->targets($changes['rowAccess']), ...$targets);
    }

    /**
     * @param  list<FieldEntry>  $entries
     * @param  RawRow  $raw
     * @return list<ReferenceTarget>
     */
    private function rowTargets(array $entries, array $raw): array
    {
        $targets = [];

        foreach ($entries as $entry) {
            $targets[] = $entry['type']->isReference() ? $this->targetsIn($raw[$entry['id']] ?? null) : [];
        }

        return array_merge([], ...$targets);
    }

    /**
     * 版の labels.options(選択の項目の id → 選択肢のコード → 名前)。形の違う値は除く。
     *
     * @param  RevisionCopy  $copy
     * @return array<int, array<string, string>>
     */
    private function optionsOf(array $copy): array
    {
        $options = is_array($copy['labels']['options'] ?? null) ? $copy['labels']['options'] : [];

        return array_map(
            fn (mixed $names) => is_array($names) ? array_filter($names, 'is_string') : [],
            array_filter($options, fn (mixed $names, int|string $id) => is_int($id), ARRAY_FILTER_USE_BOTH),
        );
    }

    /**
     * @param  list<FieldEntry>  $entries
     * @param  list<int>  $ids
     * @return list<string>
     */
    private function codesOf(array $entries, array $ids): array
    {
        $changed = array_filter($entries, fn (array $entry) => in_array($entry['id'], $ids, true));

        return array_column($changed, 'code');
    }
}
