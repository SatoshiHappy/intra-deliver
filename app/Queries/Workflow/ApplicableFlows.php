<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowProxyKind;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use LogicException;

/**
 * フローの一覧と「申請できる人」の判定(docs の 設計/ワークフロー/認可と一覧.md 3.1、案件の操作/README.md 1.3)。申請・一時保存の業務処理と can も同じ判定を使う。
 *
 * 申請できる = 判定を求める者のユーザーが d に有効で、d に有効なフローの版(ルートの深さ/データモデル/定義の表.md 2.1)の申請のノード(位置 1)の
 * 処理対象者の指定に本人の判定で合い、かつコンテンツの業務モジュールの create を許される。d に有効な版の無いフローは申請できない。ゲスト・d に有効でないユーザーはどのフローも申請できない。
 * 鍵を取らない(定義の表は鍵を取らずに読む = 設計/ワークフロー/データモデル/直列化と検査.md 3.1)。
 *
 * @phpstan-type FlowCheck Closure(Requester, list<int>, DateTimeInterface): list<int>
 */
class ApplicableFlows
{
    /** 新規の申請が業務モジュールに求めるアクション(既存のデータからの申請は呼び手が渡す) */
    private const NEW_APPLICATION = [RecordModuleAction::Create];

    public function __construct(
        private SubjectGroupMembership $membership,
        private RecordFormQuery $forms,
        private EffectiveFlowVersions $versions,
        private WorkflowProxies $proxies,
    ) {}

    /**
     * フローの一覧(設計/ワークフロー/ルートの深さ/画面/定義の管理の画面.md 2 節の flows。並び = sort_key・code。ページングしない)。自分か、申請の代理元のだれかが申請できるフロー
     * (設計/ワークフロー/案件の運用/案件の操作/README.md の 3.1 節。判定は {@see self::applicableIdsByAny()})。業務モジュールの名前はモデルの name をそのまま出す。
     *
     * @return list<array{code: string, name: string, description: string|null, moduleName: string}>
     */
    public function flows(AccessContext $context): array
    {
        $requester = Requester::fromAccessContext($context);
        $allIds = array_values(WorkflowFlow::query()->pluck('id')->all());
        $flows = WorkflowFlow::query()
            ->whereKey($this->applicableIdsByAny($requester, $context->date, $allIds))
            ->with('workflowContent.recordModule')
            ->orderBy('sort_key')->orderBy('code')->get();

        return array_values($flows->map(fn (WorkflowFlow $flow) => [
            'code' => $flow->code,
            'name' => $flow->name,
            'description' => $flow->description,
            'moduleName' => $this->moduleOf($flow)->name,
        ])->all());
    }

    /**
     * 判定を求める者が d にそのフローを申請できるか(無いフローは偽)。
     */
    public function isApplicable(Requester $requester, DateTimeInterface $date, int $flowId): bool
    {
        return $this->applicableIds($requester, $date, [$flowId]) === [$flowId];
    }

    /**
     * 新規の申請の申請者の候補(設計/ワークフロー/案件の運用/案件の操作/README.md の 3.1 節・4.5 節)。入口の 403 とフローの一覧と同じ式で求め、
     * 入口が通ることと候補が空でないことを揃える。
     *
     * @return list<int> ユーザーの id(自分を先頭に、代理元は昇順)
     */
    public function applicantIds(Requester $requester, int $flowId, DateTimeInterface $date): array
    {
        return $this->applicantIdsChecked($requester, $flowId, $date, $this->newApplicationIds(...));
    }

    /**
     * 自分か申請の代理元のだれかが d にそのフローを申請できるか(申請の系統の入口の 403 = 3.1 節)。
     */
    public function isApplicableByAny(Requester $requester, int $flowId, DateTimeInterface $date): bool
    {
        return $this->applicableIdsByAny($requester, $date, [$flowId]) === [$flowId];
    }

    /**
     * 渡したフローのうち、自分か申請の代理元のだれかが申請できるものの id(id の昇順)。
     *
     * @param  list<int>  $flowIds
     * @return list<int>
     */
    public function applicableIdsByAny(Requester $requester, DateTimeInterface $date, array $flowIds): array
    {
        return $this->applicableIdsByAnyChecked($requester, $date, $flowIds, $this->newApplicationIds(...));
    }

    /**
     * 判定 $check の申請者の候補(自分を先頭に、代理元は昇順。自分は代理元に入れない)。入口が通ることと候補が空でないことを揃える
     * (設計/ワークフロー/案件の運用/案件の操作/README.md の 3.1 節)。
     *
     * @param  FlowCheck  $check
     * @return list<int> ユーザーの id
     */
    public function applicantIdsChecked(
        Requester $requester,
        int $flowId,
        DateTimeInterface $date,
        Closure $check,
    ): array {
        return $this->applicantsByFlow($requester, [$flowId], $date, $check, true)[$flowId];
    }

    /**
     * 渡したフローのうち、自分か申請の代理元のだれかが判定 $check を通るものの id(id の昇順。3.1 節の入口)。
     *
     * @param  list<int>  $flowIds
     * @param  FlowCheck  $check
     * @return list<int>
     */
    public function applicableIdsByAnyChecked(
        Requester $requester,
        DateTimeInterface $date,
        array $flowIds,
        Closure $check,
    ): array {
        $byFlow = $this->applicantsByFlow($requester, $flowIds, $date, $check, false);
        $ids = array_keys(array_filter($byFlow, fn (array $userIds): bool => $userIds !== []));
        sort($ids);

        return $ids;
    }

    /**
     * 渡したフローのうち、判定を求める者が d に申請できるものの id(id の昇順)。行ごとの can をまとめて求めるときに使う(問い合わせの回数がフローの数に依らない
     * 部分と、業務モジュールごとの判定の部分だけになる)。
     *
     * @param  list<int>  $flowIds
     * @param  list<RecordModuleAction>  $actions  コンテンツの業務モジュールに求めるアクション(既存のデータからの申請は read と update =
     *                                             設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 1 節)
     * @return list<int>
     */
    public function applicableIds(
        Requester $requester,
        DateTimeInterface $date,
        array $flowIds,
        array $actions = self::NEW_APPLICATION,
    ): array {
        if ($flowIds === []) {
            return [];
        }

        $candidates = WorkflowFlow::query()->whereKey($flowIds)->orderBy('id');
        $flows = $this->applicable($requester, $date, $candidates, $actions);

        return array_values($flows->map(fn (WorkflowFlow $flow) => $flow->id)->all());
    }

    /**
     * 申請できる人(設計/ワークフロー/案件の運用/案件の操作/README.md の 3.1 節)を求める 1 つの式。入口と選択肢の答えを揃えるため、
     * 判定を渡す 2 つの口はここだけを通す。代理は 1 回で引く(問い合わせをフローの数に比例させない)。
     *
     * @param  list<int>  $flowIds
     * @param  FlowCheck  $check
     * @param  bool  $everyone  偽なら、自分が申請できるフローの代理元を判定しない(空かどうかだけを使う呼び手のため。空かどうかは変わらない)
     * @return array<int, list<int>> フローの id → ユーザーの id(自分を先頭に、代理元は昇順。だれもいなければ空)
     */
    private function applicantsByFlow(
        Requester $requester,
        array $flowIds,
        DateTimeInterface $date,
        Closure $check,
        bool $everyone,
    ): array {
        $byFlow = array_fill_keys($flowIds, []);
        $userId = $requester->userId;

        if ($userId === null) {
            return $byFlow;
        }

        $own = $check($requester, $flowIds, $date);

        foreach ($own as $flowId) {
            $byFlow[$flowId][] = $userId;
        }

        $principalFlowIds = $everyone ? $flowIds : array_values(array_diff($flowIds, $own));

        foreach ($this->flowsByPrincipal($userId, $principalFlowIds, $date) as $principalId => $ids) {
            foreach ($check(RecordApplyFlows::requesterAs($requester, $principalId), $ids, $date) as $flowId) {
                $byFlow[$flowId][] = $principalId;
            }
        }

        return $byFlow;
    }

    /**
     * 自分を申請の代理先とする d に有効な代理の代理元ごとの、その代理が効くフロー(代理元の昇順。自分は入れない)。
     *
     * @param  list<int>  $flowIds
     * @return array<int, list<int>>
     */
    private function flowsByPrincipal(int $userId, array $flowIds, DateTimeInterface $date): array
    {
        $flowsByPrincipal = [];
        $principalsByFlow = $this->proxies->principalIdsByFlow($userId, $flowIds, WorkflowProxyKind::Apply, $date);

        foreach ($principalsByFlow as $flowId => $principalIds) {
            foreach (array_diff($principalIds, [$userId]) as $principalId) {
                $flowsByPrincipal[$principalId][] = $flowId;
            }
        }

        ksort($flowsByPrincipal);

        return $flowsByPrincipal;
    }

    /**
     * 新規の申請の判定(コンテンツの業務モジュールの create)。
     *
     * @param  list<int>  $flowIds
     * @return list<int>
     */
    private function newApplicationIds(Requester $requester, array $flowIds, DateTimeInterface $date): array
    {
        return $this->applicableIds($requester, $date, $flowIds);
    }

    /**
     * 候補のフローのうち申請できるもの(候補の並びのまま)。
     *
     * @param  Builder<WorkflowFlow>  $candidates
     * @param  list<RecordModuleAction>  $actions
     * @return Collection<int, WorkflowFlow>
     */
    private function applicable(
        Requester $requester,
        DateTimeInterface $date,
        Builder $candidates,
        array $actions = self::NEW_APPLICATION,
    ): Collection {
        if ($this->membership->effectiveUserId($requester, $date) === null) {
            return new Collection;
        }

        $flows = $candidates->with('workflowContent.recordModule')->get();

        if ($flows->isEmpty()) {
            return $flows;
        }

        $versionIds = $this->versions->idsOn(array_values($flows->modelKeys()), $date);
        $applyNodes = WorkflowRouteNode::query()->whereIn('workflow_flow_version_id', array_values($versionIds))->where('position', 1)->get()->keyBy('workflow_flow_version_id');
        $applyGroupByFlow = array_map(fn (int $versionId): ?int => $applyNodes->get($versionId)?->authz_subject_group_id, $versionIds);
        $matched = $this->membership->matchingIds($requester, array_values(array_unique(array_filter($applyGroupByFlow, fn (?int $id): bool => $id !== null))), $date);
        $allowedByModule = [];

        return $flows->filter(function (WorkflowFlow $flow) use (
            $applyGroupByFlow, $matched, $requester, $date, $actions, &$allowedByModule,
        ) {
            if (! in_array($applyGroupByFlow[$flow->id] ?? null, $matched, true)) {
                return false;
            }

            $moduleCode = $this->moduleOf($flow)->code;

            return $allowedByModule[$moduleCode] ??= $this->allowsAll($requester, $moduleCode, $actions, $date);
        })->values();
    }

    /**
     * @param  list<RecordModuleAction>  $actions
     */
    private function allowsAll(Requester $requester, string $moduleCode, array $actions, DateTimeInterface $date): bool
    {
        foreach ($actions as $action) {
            if (! $this->forms->allows($requester, $moduleCode, $action, $date)) {
                return false;
            }
        }

        return true;
    }

    /**
     * フローのコンテンツの業務モジュール(読み込んだ関係から。外部キーで必ずある)。
     *
     * @throws LogicException 関係を読み込んでいない・行が無いとき(外部キーと読み込みの不変条件が崩れた)
     */
    private function moduleOf(WorkflowFlow $flow): RecordModule
    {
        return $flow->workflowContent->recordModule ?? throw new LogicException("フロー {$flow->code} のコンテンツの業務モジュールを読み込んでいない");
    }
}
