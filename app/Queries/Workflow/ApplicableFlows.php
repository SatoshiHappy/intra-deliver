<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Authz\Api\Requester;
use App\Queries\Authz\Api\SubjectGroupMembership;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
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
 */
class ApplicableFlows
{
    /** 新規の申請が業務モジュールに求めるアクション(既存のデータからの申請は呼び手が渡す) */
    private const NEW_APPLICATION = [RecordModuleAction::Create];

    public function __construct(
        private SubjectGroupMembership $membership,
        private RecordFormQuery $forms,
        private EffectiveFlowVersions $versions,
    ) {}

    /**
     * フローの一覧(設計/ワークフロー/ルートの深さ/画面.md 2 節の flows。並び = sort_key・code。ページングしない)。業務モジュールの名前はモデルの name をそのまま出す。
     *
     * @return list<array{code: string, name: string, description: string|null, moduleName: string}>
     */
    public function flows(AccessContext $context): array
    {
        $flows = $this->applicable(Requester::fromAccessContext($context), $context->date, WorkflowFlow::query()->orderBy('sort_key')->orderBy('code'));

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
     * 渡したフローのうち、判定を求める者が d に申請できるものの id(id の昇順)。行ごとの can をまとめて求めるときに使う(問い合わせの回数がフローの数に依らない
     * 部分と、業務モジュールごとの判定の部分だけになる)。
     *
     * @param  list<int>  $flowIds
     * @param  list<RecordModuleAction>  $actions  コンテンツの業務モジュールに求めるアクション(既存のデータからの申請は read と update =
     *                                             設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 1 節)
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
