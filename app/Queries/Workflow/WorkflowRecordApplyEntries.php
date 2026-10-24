<?php

namespace App\Queries\Workflow;

use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordApplyEntries;
use App\Queries\Records\Api\RecordApplyEntry;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;

/**
 * レコードから申請できる入口のワークフローの実装。入口はレコードの業務モジュールと作成者で決まり、そのものを申請する入口と申請対象からの申請の入口をつなぐ
 * (docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.2)。
 * 起動(App\Providers\AppServiceProvider)がタグ {@see RecordApplyEntries::TAG} で登録する。
 */
class WorkflowRecordApplyEntries implements RecordApplyEntries
{
    public function __construct(
        private RecordApplyTargets $targets,
        private RecordApplyFlows $flows,
        private RecordViewAccess $viewAccess,
    ) {}

    public function entriesFor(Requester $requester, int $recordId, DateTimeInterface $date): array
    {
        $moduleCode = $this->targets->moduleCodeOf($recordId);

        if ($moduleCode === null) {
            return [];
        }

        return [
            ...$this->recordEntries($requester, $moduleCode, $recordId, $date),
            ...$this->targetEntries($requester, $moduleCode, $recordId, $date),
        ];
    }

    /**
     * そのものを申請する入口(作成者は、自分か代理元のどれかが申請できるフローがあるときだけ読む = 業務レコードでは表示の口を呼ばない)。
     *
     * @return list<RecordApplyEntry>
     */
    private function recordEntries(
        Requester $requester,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
    ): array {
        if ($this->flows->flowsFor($requester, $moduleCode, $date) === []) {
            return [];
        }

        $creatorId = $this->targets->creatorOf($requester, $recordId, $date);

        if ($creatorId === null) {
            return [];
        }

        $query = ['record' => $recordId, ...self::applicantQuery($requester, $creatorId)];
        $flows = $this->flows->flowsAllowing($requester, $creatorId, $moduleCode, $date);

        return array_map(fn (RecordApplyFlow $flow) => self::entry($flow, $query), $flows);
    }

    /**
     * 申請対象からの申請の入口(閲覧はフローをまたいで人ごとに 1 回だけ判定する)。
     *
     * @return list<RecordApplyEntry>
     */
    private function targetEntries(
        Requester $requester,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
    ): array {
        $entries = [];
        $viewable = [];
        $canView = function (int $userId) use (&$viewable, $requester, $moduleCode, $recordId, $date): bool {
            return $viewable[$userId] ??= $this->viewAccess
                ->canView(RecordApplyFlows::requesterAs($requester, $userId), $moduleCode, $recordId, $date);
        };

        foreach ($this->flows->flowsForTarget($requester, $moduleCode, $date) as $flow) {
            $candidates = $this->flows->applicantIdsForTarget($requester, $flow->id, $date);
            $applicantId = array_find($candidates, $canView);

            if ($applicantId !== null) {
                $query = ['target' => $recordId, ...self::applicantQuery($requester, $applicantId)];
                $entries[] = self::entry($flow, $query);
            }
        }

        return $entries;
    }

    /**
     * 既定の申請者を決めるクエリ applicant(自分なら付けない = 設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。
     *
     * @return array<string, int>
     */
    private static function applicantQuery(Requester $requester, int $applicantId): array
    {
        return $applicantId === $requester->userId ? [] : ['applicant' => $applicantId];
    }

    /**
     * @param  array<string, int>  $query
     */
    private static function entry(RecordApplyFlow $flow, array $query): RecordApplyEntry
    {
        $url = route('workflow.applications.create', ['flow' => $flow->code, ...$query]);

        return new RecordApplyEntry($flow->name, $flow->description, $url);
    }
}
