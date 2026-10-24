<?php

namespace App\Queries\Workflow;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordApplyEntries;
use App\Queries\Records\Api\RecordApplyEntry;
use DateTimeInterface;

/**
 * レコードから申請できる入口のワークフローの実装(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 2 節): レコードの業務モジュールで
 * 選べるフロー({@see RecordApplyFlows::flowsFor()})を、申請の画面の URL(クエリ record)にする。起動(App\Providers\AppServiceProvider)が
 * タグ {@see RecordApplyEntries::TAG} で登録する。
 */
class WorkflowRecordApplyEntries implements RecordApplyEntries
{
    public function __construct(
        private RecordApplyTargets $targets,
        private RecordApplyFlows $flows,
    ) {}

    public function entriesFor(Requester $requester, int $recordId, DateTimeInterface $date): array
    {
        $moduleCode = $this->targets->moduleCodeOf($recordId);

        if ($moduleCode === null) {
            return [];
        }

        return array_map(fn (RecordApplyFlow $flow) => new RecordApplyEntry(
            $flow->name,
            $flow->description,
            route('workflow.applications.create', ['flow' => $flow->code, 'record' => $recordId]),
        ), $this->flows->flowsFor($requester, $moduleCode, $date));
    }
}
