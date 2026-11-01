<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordRevision;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordRevisionQuery;
use DateTimeInterface;

/**
 * 更新履歴の版 1 つの写しを詳細の形にする。値の読み方と参照の表示名は差分と同じ 1 か所を使う({@see RecordRevisionComparison}・
 * {@see RecordRevisionDiff})。docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2 節の「写しの描き方」、
 * 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.1 節。
 *
 * @phpstan-import-type RevisionView from RecordRevisionQuery
 * @phpstan-import-type RevisionField from RecordRevisionLabels
 * @phpstan-import-type FieldEntry from RecordRevisionLabels
 * @phpstan-import-type RawRow from RecordRevisionComparison
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 * @phpstan-import-type ReferenceValue from RecordValueReader
 *
 * @phpstan-type RawDetail array{field: FieldEntry, entries: list<FieldEntry>, rows: array<int, RawRow>}
 */
class RecordRevisionViews
{
    public function __construct(
        private RecordRevisionLabels $labels,
        private RecordRevisionComparison $comparison,
        private RecordRevisionDiff $differ,
        private RecordRevisionRowAccess $rowAccess,
        private RecordHeadingGroups $headings,
        private RecordValueReader $reader,
    ) {}

    /**
     * @return RevisionView
     */
    public function of(
        RecordRevision $revision,
        Requester $requester,
        DateTimeInterface $date,
        string $localeCode,
    ): array {
        $revisionSnapshot = RecordRevisionDiff::snapshotOf($revision);
        $entries = $this->labels->entries($revisionSnapshot['labels']['fields'] ?? []);
        $isValue = fn (array $entry) => $entry['type'] !== RecordFieldType::Details;
        $fields = array_values(array_filter($entries, $isValue));
        $raw = $this->comparison->rawRow($fields, $revisionSnapshot['snapshot']);
        $details = $this->rawDetails($entries, $revisionSnapshot['snapshot']);
        $referenceTargets = $this->referenceTargetsOf($fields, $raw, $details, $revisionSnapshot['snapshot']);
        $shownReferenceTargets = $this->reader->targetValues($referenceTargets, $requester, $date, $localeCode);
        $options = $this->differ->optionsOf($revisionSnapshot);

        return [
            'groups' => $this->groups($revision->record_module_id, $fields),
            'values' => $this->shown($fields, $raw, $options, $shownReferenceTargets),
            'details' => array_map(fn (array $detail) => [
                'code' => $detail['field']['code'],
                'name' => $detail['field']['name'],
                'fields' => array_map(RecordRevisionLabels::fieldOf(...), $detail['entries']),
                'rows' => array_map(
                    fn (int $id, array $row) => [
                        'id' => $id,
                        'values' => $this->shown($detail['entries'], $row, $options, $shownReferenceTargets),
                    ],
                    array_keys($detail['rows']),
                    $detail['rows'],
                ),
            ], $details),
            'rowAccess' => $this->rowAccess->shownOf($revisionSnapshot['snapshot'], $shownReferenceTargets),
        ];
    }

    /**
     * 写しの 1 行の画面の値(項目のコード → 値)。
     *
     * @param  list<FieldEntry>  $entries
     * @param  RawRow  $row
     * @param  array<int, array<string, string>>  $options  版の選択の項目の id → 選択肢のコード → 名前
     * @param  array<string, ReferenceValue>  $shownReferenceTargets  相手の鍵 → 値
     * @return array<string, mixed>
     */
    private function shown(array $entries, array $row, array $options, array $shownReferenceTargets): array
    {
        return $this->differ->row($entries, $row, $options, $shownReferenceTargets) ?? [];
    }

    /**
     * 今の見出しのまとまり(並び)に版の項目を入れる。版の項目の無いまとまりは出さない。
     *
     * @param  list<FieldEntry>  $fields  版の通常・参照の項目(版の labels の並び)
     * @return list<array{id: int|null, name: string|null, fields: list<RevisionField>}>
     */
    private function groups(int $recordModuleId, array $fields): array
    {
        $rest = array_column($fields, null, 'id');
        $currentIds = RecordField::query()->where('record_module_id', $recordModuleId)->pluck('id', 'code')->all();
        $groups = [];

        foreach ($this->headings->groups($recordModuleId) as $group) {
            $placed = [];

            foreach ($group['fieldCodes'] as $code) {
                $id = $currentIds[$code] ?? null;

                if ($id !== null && isset($rest[$id])) {
                    $placed[] = RecordRevisionLabels::fieldOf($rest[$id]);
                    unset($rest[$id]);
                }
            }

            $groups[] = ['id' => $group['id'], 'name' => $group['name'], 'fields' => $placed];
        }

        if ($rest !== []) {
            $last = array_key_last($groups) ?? 0;
            $groups[$last] ??= ['id' => null, 'name' => null, 'fields' => []];
            $removed = array_map(RecordRevisionLabels::fieldOf(...), array_values($rest));
            $groups[$last]['fields'] = [...$groups[$last]['fields'], ...$removed];
        }

        return array_values(array_filter($groups, fn (array $group) => $group['fields'] !== []));
    }

    /**
     * 版の明細の項目ごとの行(版の labels の並び。行は写しの並び)。
     *
     * @param  list<FieldEntry>  $entries  版の全部の項目
     * @return list<RawDetail>
     */
    private function rawDetails(array $entries, mixed $snapshot): array
    {
        $stored = is_array($snapshot) && is_array($snapshot['details'] ?? null) ? $snapshot['details'] : [];
        $details = [];

        foreach ($entries as $entry) {
            if ($entry['type'] === RecordFieldType::Details) {
                $children = $this->labels->entries($entry['children']);
                $rows = $this->comparison->rows($children, $stored[$entry['id']] ?? null);
                $details[] = ['field' => $entry, 'entries' => $children, 'rows' => $rows];
            }
        }

        return $details;
    }

    /**
     * 写しに出る参照の相手(項目・明細の行・参照者。表示名をまとめて引くため)。
     *
     * @param  list<FieldEntry>  $fields
     * @param  RawRow  $raw
     * @param  list<RawDetail>  $details
     * @return list<ReferenceTarget>
     */
    private function referenceTargetsOf(array $fields, array $raw, array $details, mixed $snapshot): array
    {
        $referenceTargets = [$this->differ->rowTargets($fields, $raw)];

        foreach ($details as $detail) {
            foreach ($detail['rows'] as $row) {
                $referenceTargets[] = $this->differ->rowTargets($detail['entries'], $row);
            }
        }

        return array_merge($this->rowAccess->referenceTargetsOf($snapshot), ...$referenceTargets);
    }
}
