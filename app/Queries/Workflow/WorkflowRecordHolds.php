<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Records\Api\RecordHold;
use App\Queries\Records\Api\RecordHolds;

/**
 * レコードの持ち主のワークフローの実装(docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 4 節): 未完了の案件が指すレコードは、その案件が持ち主
 * (鍵 workflow-case:<案件の id>)。完了・一時保存の案件は持ち主にならない。起動(App\Providers\AppServiceProvider)がタグ {@see RecordHolds::TAG} で登録する。
 * 案件の表を鍵なしで読む(FOR UPDATE・FOR SHARE を出さない = データモデル/直列化と検査.md 6 節の「ロックの順」の (3))。
 */
class WorkflowRecordHolds implements RecordHolds
{
    /** 拒むときの文言 */
    public const REASON = '申請中のため変更できません';

    /**
     * 案件の持ち主の鍵(workflow-case:<案件の id>)を作る 1 か所。鍵を渡してよいのは持ち主自身(ワークフローの再申請の業務処理)だけ
     * (docs の 設計/ワークフロー/業務データとの接続/申請と書き込み.md 4 節)。
     */
    public static function holderOf(int $caseId): string
    {
        return "workflow-case:{$caseId}";
    }

    /**
     * 1 回の問い合わせで引く(渡したレコードが無ければ問い合わせない)。
     *
     * @param  list<int>  $recordIds
     * @return array<int, RecordHold>
     */
    public function holdsOf(array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        return WorkflowCase::query()
            ->where('state', WorkflowCaseState::Active)
            ->whereIn('record_id', $recordIds)
            ->get(['id', 'record_id'])
            ->mapWithKeys(fn (WorkflowCase $case) => [(int) $case->record_id => $this->holdOf($case)])
            ->all();
    }

    /**
     * その業務モジュールのレコードを指す未完了の案件のうち id の最も小さいものの持ち主(1 回の問い合わせ。レコードの表と結合し、レコードの id を列挙しない)。
     */
    public function firstHoldInModule(int $recordModuleId): ?RecordHold
    {
        $case = WorkflowCase::query()
            ->join('records', 'records.id', '=', 'workflow_cases.record_id')
            ->where('workflow_cases.state', WorkflowCaseState::Active)
            ->where('records.record_module_id', $recordModuleId)
            ->orderBy('workflow_cases.id')
            ->first(['workflow_cases.id']);

        return $case === null ? null : $this->holdOf($case);
    }

    private function holdOf(WorkflowCase $case): RecordHold
    {
        return new RecordHold(self::holderOf($case->id), self::REASON);
    }
}
