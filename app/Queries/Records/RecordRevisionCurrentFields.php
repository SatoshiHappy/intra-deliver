<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordFieldType;
use App\Models\Records\RecordField;
use App\Models\Records\RecordModule;

/**
 * 版の比べた結果を、今の定義で描ける項目(後の版の写しの項目と同じ id の項目が今の定義にあるもの)だけに絞る。
 * 項目のコードは消した後に再利用できるので、コードで今の定義を引いて描く処理履歴の差分が別の項目を誤った型で描かないようにする
 * (docs の 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節。処理履歴の差分と一緒に消す)。
 *
 * @phpstan-import-type Changes from RecordRevisionComparison
 * @phpstan-import-type FieldEntry from RecordRevisionLabels
 * @phpstan-import-type RawRowChange from RecordRevisionComparison
 */
class RecordRevisionCurrentFields
{
    /**
     * @param  Changes  $changes
     * @param  RecordModule  $module  {@see RecordDefinitionQuery::load()} で今の定義を読み込んだ業務モジュール
     * @return Changes
     */
    public function filter(array $changes, RecordModule $module): array
    {
        $current = $this->idsOf($module->recordFields);
        $details = [];

        foreach ($changes['details'] as $detail) {
            $child = $module->recordFields->first(fn (RecordField $field) => $field->id === $detail['field']['id']);
            $childIds = $this->idsOf($child?->childRecordModule->recordFields ?? []);
            $isCurrent = fn (array $entry) => isset($childIds[$entry['id']]);
            $entries = array_values(array_filter($detail['entries'], $isCurrent));
            $rows = $this->rows($detail['rows'], $childIds);

            if ($child !== null && $rows !== []) {
                $details[] = [...$detail, 'entries' => $entries, 'rows' => $rows];
            }
        }

        return [
            'fields' => array_values(array_filter(
                $changes['fields'],
                fn (array $change) => isset($current[$change['field']['id']]),
            )),
            'details' => $details,
            'attachments' => $changes['attachments'],
            'rowAccess' => $changes['rowAccess'],
        ];
    }

    /**
     * 明細の行の変化を今の明細の項目に絞る(変わった項目が全部除かれた変更の行は除く)。
     *
     * @param  list<RawRowChange>  $rows
     * @param  array<int, true>  $childIds
     * @return list<RawRowChange>
     */
    private function rows(array $rows, array $childIds): array
    {
        $kept = [];

        foreach ($rows as $row) {
            $changedIds = array_values(array_filter($row['changedIds'], fn (int $id) => isset($childIds[$id])));

            if ($row['change'] !== 'changed' || $changedIds !== []) {
                $kept[] = [...$row, 'changedIds' => $changedIds];
            }
        }

        return $kept;
    }

    /**
     * 明細を除いた項目の id(id → 真)。
     *
     * @param  iterable<RecordField>  $fields
     * @return array<int, true>
     */
    private function idsOf(iterable $fields): array
    {
        $ids = [];

        foreach ($fields as $field) {
            if ($field->type !== RecordFieldType::Details) {
                $ids[$field->id] = true;
            }
        }

        return $ids;
    }
}
