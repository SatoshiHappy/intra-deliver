<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowContent;
use App\Queries\Records\Api\RecordRelations;

/**
 * 申請対象で当てる案件の、申請書のレコードを引く(docs の 設計/ワークフロー/業務データとの接続/写しとつながる申請.md 6 節の「引き方」、
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.4 節)。返すのはレコードの id だけで、
 * 案件の閲覧の判定は呼び手が掛ける。
 */
class ApplicationTargetCases
{
    public function __construct(private RecordRelations $relations) {}

    /**
     * 業務モジュールのコード → その業務モジュールを申請対象の参照先にするコンテンツの、申請対象の項目の id(項目の id の昇順)。
     *
     * @return array<string, list<int>>
     */
    public function fieldIdsByModule(): array
    {
        $rows = WorkflowContent::query()
            ->join('record_modules', 'record_modules.id', '=', 'workflow_contents.target_record_module_id')
            ->whereNotNull('workflow_contents.target_record_field_id')
            ->orderBy('workflow_contents.target_record_field_id')
            ->toBase()
            ->get(['record_modules.code', 'workflow_contents.target_record_field_id']);
        $byModule = [];

        foreach ($rows as $row) {
            $byModule[(string) $row->code][] = (int) $row->target_record_field_id;
        }

        return $byModule;
    }

    /**
     * レコードを申請対象にする申請書のレコードの id(昇順)。申請対象の項目が無ければ読まない。参照元を項目で引く読み取りは主体の判定を
     * しないので、結果は呼び手が案件の閲覧の判定で絞る(設計/ワークフロー/業務データとの接続/公開の口.md の 7 節)。
     *
     * @param  list<int>  $fieldIds  申請対象の項目の id({@see self::fieldIdsByModule()})
     * @return list<int>
     */
    public function applicationRecordIdsOf(int $recordId, array $fieldIds): array
    {
        if ($fieldIds === []) {
            return [];
        }

        $ids = [];

        foreach ($this->relations->referencingByField($recordId, $fieldIds) as $bundle) {
            $ids = [...$ids, ...$bundle['recordIds']];
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }
}
