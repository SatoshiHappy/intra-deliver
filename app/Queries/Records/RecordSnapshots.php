<?php

namespace App\Queries\Records;

use App\Models\Records\Record;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordModule;
use App\Models\Records\RecordReference;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * レコードの今の保存形の写し(更新履歴の版の snapshot。形 = docs の
 * 設計/業務データの入れ物/レコードの共通機能/データモデル/表.md の 2.1.1 節)。写しは Records の内部だけが作り、ほかのモジュールは
 * 版の id で指す(更新履歴.md の 4.2 節)。jsonb に書く形はモデルのキャストが決める。
 *
 * @phpstan-import-type ReferenceTarget from RecordValueReader
 *
 * @phpstan-type SnapshotReferences array<int, ReferenceTarget|list<ReferenceTarget>>
 * @phpstan-type SnapshotRow array{id: int, values: array<int, mixed>, references: SnapshotReferences}
 * @phpstan-type SnapshotAttachment array{id: int, upload_id: int, name: string, size: int, mime: string}
 * @phpstan-type Snapshot array{values: array<int, mixed>, references: SnapshotReferences,
 *     details: array<int, list<SnapshotRow>>, attachments: list<SnapshotAttachment>}
 */
class RecordSnapshots
{
    public function __construct(
        private RecordDefinitionQuery $definitions,
        private RecordValueReader $reader,
    ) {}

    /**
     * 鍵を取らず、呼び手のトランザクションの中で読む(呼び手が書いた直後に、同じトランザクションで呼ぶ)。
     *
     * @param  Record  $record  親の無いレコード
     * @param  RecordModule  $module  レコードの業務モジュール({@see RecordDefinitionQuery::load()} で定義を読み込んだもの)
     * @return Snapshot
     */
    public function snapshot(Record $record, RecordModule $module): array
    {
        $rows = Record::query()->where('parent_record_id', $record->id)->orderBy('sort_key')->orderBy('id')->get();
        $references = RecordReference::query()->whereIn('record_id', [$record->id, ...$rows->modelKeys()])
            ->orderBy('record_field_id')->orderBy('sort_key')->get();
        $details = [];

        foreach ($this->definitions->detailsFields($module) as $field) {
            $fieldRows = $rows->where('record_module_id', $field->child_record_module_id);
            $details[$field->id] = array_values($fieldRows->map(fn (Record $row) => [
                'id' => $row->id,
                'values' => $row->field_values ?? [],
                'references' => $this->targets($references, $row->id),
            ])->all());
        }

        return [
            'values' => $record->field_values ?? [],
            'references' => $this->targets($references, $record->id),
            'details' => $details,
            'attachments' => $this->attachments($record->id),
        ];
    }

    /**
     * レコードの参照の行の相手(項目の id → 相手。複数選べる参照(行の is_multiple)は sort_key の順の相手の配列)。
     *
     * @param  Collection<int, RecordReference>  $references  項目の id・sort_key の順
     * @return SnapshotReferences
     */
    private function targets(Collection $references, int $recordId): array
    {
        $targets = [];

        foreach ($references->where('record_id', $recordId)->groupBy('record_field_id') as $fieldId => $rows) {
            $found = array_values($rows->map(fn (RecordReference $row) => $this->reader->target($row))->all());
            $targets[(int) $fieldId] = $rows->firstOrFail()->is_multiple ? $found : $found[0];
        }

        return $targets;
    }

    /**
     * @return list<SnapshotAttachment>
     *
     * @throws LogicException 添付の台帳の行が無いとき(外部キーの不変条件が崩れた)
     */
    private function attachments(int $recordId): array
    {
        $attachments = RecordAttachment::query()->with('upload')->where('record_id', $recordId)->orderBy('id')->get();

        return array_values($attachments->map(function (RecordAttachment $attachment): array {
            $upload = $attachment->upload ?? throw new LogicException("添付 {$attachment->id} の台帳の行が無い");

            return [
                'id' => $attachment->id,
                'upload_id' => $upload->id,
                'name' => $attachment->name,
                'size' => $upload->size,
                'mime' => $upload->mime,
            ];
        })->all());
    }
}
