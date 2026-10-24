<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * 既存のデータからの申請をできる人の判定の 1 か所(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 1 節)と、
 * 申請対象からの申請の口(…ForTarget = 同じ文書の 申請対象からの申請.md の 9.1)。
 * 申請のノードの対象者の判定は新規の申請と同じ {@see ApplicableFlows} を呼ぶ。そのものを申請するときは業務モジュールに求めるアクションを
 * read と update にし、申請対象からの申請は申請書の業務モジュールの create に元の業務モジュールの read を足す。
 * どの口もレコードを受けない(レコードの有無・作成者・閲覧は呼び手が見る)。鍵を取らない。
 *
 * @phpstan-type FlowCheck Closure(Requester, list<int>, DateTimeInterface): list<int>
 */
class RecordApplyFlows
{
    /** コンテンツの業務モジュールに求めるアクション(create は求めない) */
    public const ACTIONS = [RecordModuleAction::Read, RecordModuleAction::Update];

    public function __construct(
        private ApplicableFlows $applicableFlows,
        private WorkflowProxies $proxies,
        private RecordFormQuery $forms,
    ) {}

    /**
     * 選べるフロー: d に有効な版があり、コンテンツの業務モジュールが $moduleCode で、自分か、自分を申請の代理先とする代理元のどれかが
     * 既存のデータからの申請をできるもの(並び = フローの一覧と同じ sort_key・code)。
     *
     * @return list<RecordApplyFlow>
     */
    public function flowsFor(Requester $requester, string $moduleCode, DateTimeInterface $date): array
    {
        $flows = $this->listed('workflowContent.recordModule', 'code', $moduleCode);
        $allowed = $this->allowedIds($requester, array_values($flows->modelKeys()), $date, $this->recordIds(...));

        return self::flowsIn($flows, $allowed);
    }

    /**
     * 自分か、自分を申請の代理先とする d に有効でそのフローに効く代理の代理元のどれかが、d にそのフローで既存のデータからの申請をできるか。
     */
    public function allowsAny(Requester $requester, int $flowId, DateTimeInterface $date): bool
    {
        return $this->allowedIds($requester, [$flowId], $date, $this->recordIds(...)) !== [];
    }

    /**
     * その人だけが、d にそのフローで既存のデータからの申請をできるか(代理元を見ない。無いフローは偽)。
     */
    public function allows(Requester $applicant, int $flowId, DateTimeInterface $date): bool
    {
        return $this->recordIds($applicant, [$flowId], $date) === [$flowId];
    }

    /**
     * 申請者の候補: 自分と、自分を申請の代理先とする d に有効でそのフローに効く代理の代理元を、1 人ずつ {@see self::allows()} で判定した残り
     * (自分を先頭に、代理元は昇順)。空でないことは {@see self::allowsAny()} と同じ。レコードの作成者で絞るのは呼び手。
     *
     * @return list<int> ユーザーの id
     */
    public function applicantIds(Requester $requester, int $flowId, DateTimeInterface $date): array
    {
        return $this->candidateIds($requester, $flowId, $date, $this->recordIds(...));
    }

    /**
     * 入口のフロー: コンテンツの業務モジュールが $moduleCode で、$applicantId(自分か、自分を申請の代理先とする d に有効でそのフローに効く
     * 代理の代理元)が {@see self::allows()} のもの(並び = フローの一覧。申請書のレコードの作成者を渡す = 既存のデータからの申請/README.md の 2 節)。
     *
     * @return list<RecordApplyFlow>
     */
    public function flowsAllowing(
        Requester $requester,
        int $applicantId,
        string $moduleCode,
        DateTimeInterface $date,
    ): array {
        $flows = $this->listed('workflowContent.recordModule', 'code', $moduleCode);
        $flowIds = array_values($flows->modelKeys());

        if ($applicantId !== $requester->userId) {
            $principalsByFlow = $requester->userId === null ? [] : $this->proxies
                ->principalIdsByFlow($requester->userId, $flowIds, WorkflowProxyKind::Apply, $date);
            $byApplicant = array_filter($principalsByFlow, fn (array $ids) => in_array($applicantId, $ids, true));
            $flowIds = array_keys($byApplicant);
        }

        $allowed = $this->recordIds(self::requesterAs($requester, $applicantId), $flowIds, $date);

        return self::flowsIn($flows, $allowed);
    }

    /**
     * 申請対象からの申請の選べるフロー: d に有効な版があり、コンテンツの申請対象の参照先が $targetModuleCode で、自分か代理元のどれかが
     * この経路で申請できるもの(並び = フローの一覧)。レコードの閲覧は呼び手が人ごとに足す。
     *
     * @return list<RecordApplyFlow>
     */
    public function flowsForTarget(Requester $requester, string $targetModuleCode, DateTimeInterface $date): array
    {
        $moduleId = RecordModule::query()->where('code', $targetModuleCode)->value('id');

        if ($moduleId === null) {
            return [];
        }

        $flows = $this->listed('workflowContent', 'target_record_module_id', $moduleId);
        $flowIds = array_values($flows->modelKeys());
        $allowed = $this->allowedIds($requester, $flowIds, $date, $this->targetIds(...));

        return self::flowsIn($flows, $allowed);
    }

    /**
     * 自分か代理元のどれかが、d にそのフローで申請対象からの申請をできるか。
     */
    public function allowsAnyForTarget(Requester $requester, int $flowId, DateTimeInterface $date): bool
    {
        return $this->allowedIds($requester, [$flowId], $date, $this->targetIds(...)) !== [];
    }

    /**
     * その人だけが、d にそのフローで申請対象からの申請をできるか(申請対象の無いコンテンツのフロー・無いフローは偽)。
     */
    public function allowsForTarget(Requester $applicant, int $flowId, DateTimeInterface $date): bool
    {
        return $this->targetIds($applicant, [$flowId], $date) === [$flowId];
    }

    /**
     * 申請対象からの申請の申請者の候補(自分を先頭に代理元は昇順。1 人ずつ {@see self::allowsForTarget()} で判定した残り)。
     *
     * @return list<int> ユーザーの id
     */
    public function applicantIdsForTarget(Requester $requester, int $flowId, DateTimeInterface $date): array
    {
        return $this->candidateIds($requester, $flowId, $date, $this->targetIds(...));
    }

    /**
     * 代理元を判定を求める者にする(選んだ組織なし = {@see PrincipalContext} と同じ)。自分なら渡した値のまま。
     */
    public static function requesterAs(Requester $requester, int $userId): Requester
    {
        return $userId === $requester->userId ? $requester : new Requester($userId, $requester->ipv4Address);
    }

    /**
     * そのものを申請する判定: 渡したフローのうち、その人が申請のノードの対象者で、コンテンツの業務モジュールの read と update を許されるもの。
     *
     * @param  list<int>  $flowIds
     * @return list<int>
     */
    private function recordIds(Requester $requester, array $flowIds, DateTimeInterface $date): array
    {
        return $this->applicableFlows->applicableIds($requester, $date, $flowIds, self::ACTIONS);
    }

    /**
     * 申請対象からの申請の判定: 新規の申請ができ(申請のノードの対象者 + 申請書の業務モジュールの create)、申請対象の参照先の業務モジュールの
     * read を許されるもの。申請対象の無いコンテンツのフローは除く。
     *
     * @param  list<int>  $flowIds
     * @return list<int>
     */
    private function targetIds(Requester $requester, array $flowIds, DateTimeInterface $date): array
    {
        $targets = $this->targetModuleCodes($flowIds);
        $applicable = $this->applicableFlows->applicableIds($requester, $date, array_keys($targets));
        $readable = [];

        foreach (array_unique(array_intersect_key($targets, array_flip($applicable))) as $moduleCode) {
            $readable[$moduleCode] = $this->forms->allows($requester, $moduleCode, RecordModuleAction::Read, $date);
        }

        return array_values(array_filter($applicable, fn (int $flowId): bool => $readable[$targets[$flowId]]));
    }

    /**
     * 渡したフローのうち、コンテンツに申請対象があるものの、フローの id → 申請対象の参照先の業務モジュールのコード。
     *
     * @param  list<int>  $flowIds
     * @return array<int, string>
     */
    private function targetModuleCodes(array $flowIds): array
    {
        if ($flowIds === []) {
            return [];
        }

        $moduleIds = [];

        foreach (WorkflowFlow::query()->with('workflowContent')->whereKey($flowIds)->get() as $flow) {
            $moduleId = $flow->workflowContent?->target_record_module_id;

            if ($moduleId !== null) {
                $moduleIds[$flow->id] = (int) $moduleId;
            }
        }

        $codes = RecordModule::query()->whereKey(array_values($moduleIds))->pluck('code', 'id')->all();

        return array_map(fn (int $moduleId): string => (string) $codes[$moduleId], $moduleIds);
    }

    /**
     * 自分と代理元を 1 人ずつ判定した残り(自分を先頭に、代理元は昇順)。
     *
     * @param  FlowCheck  $check
     * @return list<int>
     */
    private function candidateIds(Requester $requester, int $flowId, DateTimeInterface $date, Closure $check): array
    {
        $userId = $requester->userId;

        if ($userId === null) {
            return [];
        }

        $principals = $this->proxies->principalIdsOf($userId, $flowId, WorkflowProxyKind::Apply, $date);
        $allowed = fn (int $id): bool => $check(self::requesterAs($requester, $id), [$flowId], $date) === [$flowId];

        return array_values(array_filter([$userId, ...array_diff($principals, [$userId])], $allowed));
    }

    /**
     * 渡したフローのうち、自分か代理元のどれかができるものの id(代理元は自分ができないフローだけを、代理元ごとにまとめて判定する。
     * 代理元は 1 回で引き、問い合わせの数をフローの数に比例させない)。
     *
     * @param  list<int>  $flowIds
     * @param  FlowCheck  $check
     * @return list<int>
     */
    private function allowedIds(Requester $requester, array $flowIds, DateTimeInterface $date, Closure $check): array
    {
        $allowed = $check($requester, $flowIds, $date);
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
            $allowed = [...$allowed, ...$check(self::requesterAs($requester, $principalId), $ids, $date)];
        }

        return $allowed;
    }

    /**
     * フローの一覧と同じ並びで、関係の列の値で絞ったフロー。
     *
     * @return Collection<int, WorkflowFlow>
     */
    private function listed(string $relation, string $column, mixed $value): Collection
    {
        return WorkflowFlow::query()->whereRelation($relation, $column, $value)
            ->orderBy('sort_key')->orderBy('code')->get();
    }

    /**
     * @param  Collection<int, WorkflowFlow>  $flows
     * @param  list<int>  $allowed
     * @return list<RecordApplyFlow>
     */
    private static function flowsIn(Collection $flows, array $allowed): array
    {
        $listed = $flows->filter(fn (WorkflowFlow $flow) => in_array($flow->id, $allowed, true));

        return array_values($listed->map(
            fn (WorkflowFlow $flow) => new RecordApplyFlow($flow->id, $flow->code, $flow->name, $flow->description),
        )->all());
    }
}
