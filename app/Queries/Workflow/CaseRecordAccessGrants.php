<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordAccessGrants;
use DateTimeInterface;

/**
 * 申請の経路の実装(docs の 設計/ワークフロー/申請の周辺/申請の内容の閲覧.md の 1 節。口の形 =
 * 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.2 節)。起動(App\Providers\AppServiceProvider)が
 * タグ {@see RecordAccessGrants::TAG} で登録する。
 */
class CaseRecordAccessGrants implements RecordAccessGrants
{
    public function __construct(private CaseVisibility $visibility) {}

    /**
     * 集合に案件の閲覧の述語を 1 回で掛ける(問い合わせの数がレコードの数で増えない)。無い id は結果に入らない。
     */
    public function grantedViews(Requester $requester, array $recordIds, DateTimeInterface $date): array
    {
        if ($recordIds === []) {
            return [];
        }

        $cases = WorkflowCase::query()->where('state', WorkflowCaseState::Active)->whereIn('record_id', $recordIds);
        $visible = $this->visibility->visible($cases, $requester, $date)->orderBy('id')->get(['id', 'record_id']);
        $views = [];

        foreach ($visible as $case) {
            $views[(int) $case->record_id] ??= route('workflow.cases.show', ['case' => $case->id]);
        }

        return $views;
    }
}
