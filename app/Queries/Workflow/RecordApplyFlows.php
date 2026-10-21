<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * 既存のデータからの申請をできる人の判定の 1 か所(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 1 節)。
 * 申請のノードの対象者の判定は新規の申請と同じ {@see ApplicableFlows} を呼び、業務モジュールに求めるアクションだけを read と update にする。
 * どの口もレコードを受けない(レコードの有無と業務モジュールの一致は呼び手の 404 の段で見る)。鍵を取らない。
 */
class RecordApplyFlows
{
    /** コンテンツの業務モジュールに求めるアクション(create は求めない) */
    public const ACTIONS = [RecordModuleAction::Read, RecordModuleAction::Update];

    public function __construct(
        private ApplicableFlows $applicableFlows,
        private WorkflowProxies $proxies,
    ) {}

    /**
     * 選べるフロー: d に有効な版があり、コンテンツの業務モジュールが $moduleCode で、自分か、自分を申請の代理先とする代理元のどれかが
     * 既存のデータからの申請をできるもの(並び = フローの一覧と同じ sort_key・code)。
     *
     * @return list<RecordApplyFlow>
     */
    public function flowsFor(Requester $requester, string $moduleCode, DateTimeInterface $date): array
    {
        $flows = WorkflowFlow::query()
            ->whereHas('workflowContent.recordModule', fn (Builder $module) => $module->where('code', $moduleCode))
            ->orderBy('sort_key')->orderBy('code')->get();
        $allowed = $this->allowedIds($requester, array_values($flows->modelKeys()), $date);
        $listed = $flows->filter(fn (WorkflowFlow $flow) => in_array($flow->id, $allowed, true));

        return array_values($listed->map(
            fn (WorkflowFlow $flow) => new RecordApplyFlow($flow->id, $flow->code, $flow->name, $flow->description),
        )->all());
    }

    /**
     * 自分か、自分を申請の代理先とする d に有効でそのフローに効く代理の代理元のどれかが、d にそのフローで既存のデータからの申請をできるか。
     */
    public function allowsAny(Requester $requester, int $flowId, DateTimeInterface $date): bool
    {
        return $this->allowedIds($requester, [$flowId], $date) !== [];
    }

    /**
     * その人だけが、d にそのフローで既存のデータからの申請をできるか(代理元を見ない。無いフローは偽)。
     */
    public function allows(Requester $applicant, int $flowId, DateTimeInterface $date): bool
    {
        return $this->applicableFlows->applicableIds($applicant, $date, [$flowId], self::ACTIONS) === [$flowId];
    }

    /**
     * 申請者の候補: 自分と、自分を申請の代理先とする d に有効でそのフローに効く代理の代理元を、1 人ずつ {@see self::allows()} で判定した残り
     * (自分を先頭に、代理元は昇順)。空でないことは {@see self::allowsAny()} と同じ。
     *
     * @return list<int> ユーザーの id
     */
    public function applicantIds(Requester $requester, int $flowId, DateTimeInterface $date): array
    {
        $userId = $requester->userId;

        if ($userId === null) {
            return [];
        }

        $principals = array_diff($this->principalIdsOf($userId, $flowId, $date), [$userId]);
        $allowed = fn (int $id): bool => $this->allows($this->requesterAs($requester, $id), $flowId, $date);

        return array_values(array_filter([$userId, ...$principals], $allowed));
    }

    /**
     * 渡したフローのうち、自分か代理元のどれかができるものの id(代理元は自分ができないフローだけを、代理元ごとにまとめて判定する。
     * 代理元は 1 回で引き、問い合わせの数をフローの数に比例させない)。
     *
     * @param  list<int>  $flowIds
     * @return list<int>
     */
    private function allowedIds(Requester $requester, array $flowIds, DateTimeInterface $date): array
    {
        $allowed = $this->applicableFlows->applicableIds($requester, $date, $flowIds, self::ACTIONS);
        $userId = $requester->userId;

        if ($userId === null) {
            return $allowed;
        }

        $flowsByPrincipal = [];
        $rest = array_values(array_diff($flowIds, $allowed));
        $principalsByFlow = $this->proxies->principalIdsByFlow($userId, $rest, WorkflowProxyKind::Apply, $date);

        foreach ($principalsByFlow as $flowId => $principalIds) {
            foreach ($principalIds as $principalId) {
                $flowsByPrincipal[$principalId][] = $flowId;
            }
        }

        foreach ($flowsByPrincipal as $principalId => $ids) {
            $principal = $this->requesterAs($requester, $principalId);
            $allowed = [...$allowed, ...$this->applicableFlows->applicableIds($principal, $date, $ids, self::ACTIONS)];
        }

        return $allowed;
    }

    /**
     * 自分を申請の代理先とする、d に有効でそのフローに効く代理の代理元(昇順)。
     *
     * @return list<int>
     */
    private function principalIdsOf(int $userId, int $flowId, DateTimeInterface $date): array
    {
        return $this->proxies->principalIdsOf($userId, $flowId, WorkflowProxyKind::Apply, $date);
    }

    /**
     * 代理元を判定を求める者にする(選んだ組織なし = {@see PrincipalContext} と同じ)。自分なら渡した値のまま。
     */
    private function requesterAs(Requester $requester, int $userId): Requester
    {
        return $userId === $requester->userId ? $requester : new Requester($userId, $requester->ipv4Address);
    }
}
