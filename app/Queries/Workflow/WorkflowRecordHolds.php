<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Records\Api\RecordHold;
use App\Queries\Records\Api\RecordHolds;

/**
 * レコードの持ち主のワークフローの実装(持ち主の範囲 = 設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節)。
 * 起動(App\Providers\AppServiceProvider)がタグ {@see RecordHolds::TAG} で登録する。
 * 案件の表を鍵なしで読む(設計/ワークフロー/データモデル/直列化と検査.md の 6 節の「ロックの順」の (3))。
 */
class WorkflowRecordHolds implements RecordHolds
{
    /** 拒むときの文言(未完了・完了で同じ) */
    public const REASON = '申請済みのため変更できません';

    /**
     * 持ち主の鍵(workflow-case:<案件の id>)を作る 1 か所。鍵を渡してよいのは持ち主自身だけ
     * (設計/ワークフロー/業務データとの接続/申請と書き込み.md の 4 節)。
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
            ->where('state', '!=', WorkflowCaseState::Draft)
            ->whereIn('record_id', $recordIds)
            ->get(['id', 'record_id'])
            ->mapWithKeys(fn (WorkflowCase $case) => [(int) $case->record_id => $this->holdOf($case)])
            ->all();
    }

    /**
     * その業務モジュールのレコードを指す一時保存でない案件のうち id の最も小さいものの持ち主(1 回の問い合わせ。レコードの表と結合し、レコードの id を列挙しない)。
     */
    public function firstHoldInModule(int $recordModuleId): ?RecordHold
    {
        $case = WorkflowCase::query()
            ->join('records', 'records.id', '=', 'workflow_cases.record_id')
            ->where('workflow_cases.state', '!=', WorkflowCaseState::Draft)
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
