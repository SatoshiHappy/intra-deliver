<?php

namespace App\Queries\Workflow;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordRevisionQuery;
use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Queries\Records\Api\RecordViewAccess;
use App\Support\AccessContext;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 申請の詳細の props を表の層で組み、recordAccess が偽ならレコードの層を伏せる 1 か所(伏せる規則を 1 か所にするため。
 * docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2 節・6 節、
 * 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.4 節)。
 *
 * @phpstan-import-type RelatedGroup from RelatedCases
 */
class CaseShowProps
{
    /** 申請の層のうち {@see CaseDetailQuery} が組むもの(申請の閲覧の判定のまま返す) */
    public const DETAIL_LAYER = [
        'case', 'nodes', 'edges', 'councils', 'histories', 'can', 'nodeActions', 'pullBackNodes', 'casePrincipal',
        'proxyNote', 'canAttachOnWithdraw', 'attachmentLimits', 'operation', 'confirmations', 'commentMaxLength',
        'links',
    ];

    /**
     * 申請の層(つながる申請と、ボタン「複製して申請」を出すか = 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の 12.2 節を含む)。
     */
    public const CASE_LAYER = [...self::DETAIL_LAYER, 'related', 'canCopy'];

    /** レコードの層(props → recordAccess が偽のときの値) */
    public const RECORD_LAYER = [
        'content' => null,
        'headings' => [],
        'panel' => null,
        'sections' => [],
        'panelContent' => null,
        'relatedRecords' => null,
        'relatedRecordRows' => null,
        'relatedRecordForm' => null,
    ];

    /** 写しの層(recordAccess が真のときは null) */
    public const SNAPSHOT_LAYER = ['submittedRecord', 'maskedRelatedTab'];

    /** 最初の応答に入らない props(部分の再読み込みで求めたときだけ組む) */
    public const OPTIONAL = ['relatedRecordForm'];

    public function __construct(
        private CaseDetailQuery $detail,
        private RecordViewAccess $access,
        private RecordDisplayQuery $display,
        private RecordRevisionQuery $revisions,
        private RecordShowPanelQuery $panels,
        private CaseRecordPanel $panel,
        private CaseRelatedRecords $relatedRecords,
        private RelatedCases $related,
        private CaseCopyability $copyability,
    ) {}

    /**
     * @return array<string, mixed> 求められた props と recordAccess(いつも入れる)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できない・一時保存のとき(組み立ての後の 2 回目の判定で見えなくなったときを含む)
     */
    public function of(AccessContext $context, int $caseId, CaseShowSelection $selection): array
    {
        $requester = Requester::fromAccessContext($context);
        $case = $this->detail->visibleCase($requester, $context, $caseId);
        $scope = $this->scopeOf($context, $requester, $case, $selection);
        $access = $this->canView($scope);
        $props = $selection->wantsAny(self::DETAIL_LAYER) ? $this->detail->show($context, $caseId) : [];
        $props = [...$props, ...$this->layers($access, $scope)];
        $this->detail->visibleCase($requester, $context, $caseId);

        if ($access && ! $this->canView($scope)) {
            $access = false;
            $props = [...$props, ...$this->layers(false, $scope)];
        }

        // レコードの中身を持たず recordAccess によらないので、伏せ直しても組み直さない(判定は 1 回)
        if ($selection->wants('canCopy')) {
            $props['canCopy'] = $this->copyability->canCopy($context, (int) $case->workflow_flow_id);
        }

        return [...$props, 'recordAccess' => $access];
    }

    /**
     * レコードの層と写しの層のうち、求められた props(recordAccess が真なら今の中身、偽なら表の偽のときの値と写し)。
     *
     * @return array<string, mixed>
     */
    private function layers(bool $access, CaseShowScope $scope): array
    {
        $props = [];

        foreach ($access ? $this->openBuilders($scope) : $this->maskedBuilders($scope) as $key => $build) {
            if ($scope->selection->wants($key, in_array($key, self::OPTIONAL, true))) {
                $props[$key] = $build();
            }
        }

        return $props;
    }

    /**
     * recordAccess が真のときのレコードの層と写しの層(サイドパネルは口が読む時に、関連レコードは呼ぶ前の判定 = recordAccess で閲覧を確かめる)。
     *
     * @return array<string, Closure(): mixed>
     */
    private function openBuilders(CaseShowScope $scope): array
    {
        [$context, $requester, $recordId] = [$scope->context, $scope->requester, $scope->recordId];
        [$date, $localeCode] = [$context->date, $context->localeCode];
        $panel = self::once(fn () => $this->panels->panel($context, $scope->moduleCode, $recordId));

        return [
            'content' => fn () => [
                ...($scope->current)(),
                'updatedAfterSubmission' => $this->revisions->hasRevisionAfter(($scope->latestRevisionId)()),
            ],
            'headings' => fn () => $this->panels->headings($scope->recordModuleId),
            'panel' => fn () => $panel()['panel'] ?? null,
            'sections' => fn () => $panel()['sections'] ?? [],
            'panelContent' => fn () => $this->panel
                ->content($context, $scope->moduleCode, $recordId, $scope->selection),
            'relatedRecords' => fn () => $this->relatedRecords->records($requester, $recordId, $date),
            'relatedRecordRows' => fn () => $this->relatedRecords
                ->rows($requester, $recordId, $scope->selection, $date, $localeCode),
            'relatedRecordForm' => fn () => $this->relatedRecords
                ->form($requester, $recordId, $scope->selection, $date, $localeCode),
            'submittedRecord' => fn () => null,
            'maskedRelatedTab' => fn () => null,
            'related' => fn () => $this->relatedOf(true, $scope),
        ];
    }

    /**
     * recordAccess が偽のときのレコードの層(表の偽のときの値)と写しの層(最後の申請・再申請の版の写しと、件数を持たない関連レコードのタブ)。
     *
     * @return array<string, Closure(): mixed>
     */
    private function maskedBuilders(CaseShowScope $scope): array
    {
        return [
            ...array_map(fn (mixed $masked) => fn () => $masked, self::RECORD_LAYER),
            'submittedRecord' => $scope->submitted,
            'maskedRelatedTab' => fn () => $this->relatedRecords
                ->maskedTab($scope->requester, $scope->recordId, $scope->context->date),
            'related' => fn () => $this->relatedOf(false, $scope),
        ];
    }

    /**
     * つながる申請。recordAccess が偽なら申請書のレコードからたどらず、申請対象は最後の申請・再申請の版の写しの値から引く
     * (設計/ワークフロー/業務データとの接続/写しとつながる申請.md の 6 節)。
     *
     * @return list<RelatedGroup>
     */
    private function relatedOf(bool $access, CaseShowScope $scope): array
    {
        $values = $access ? ($scope->current)()['record']['values'] : ($scope->submitted)()['values'];
        [$date, $localeCode] = [$scope->context->date, $scope->context->localeCode];

        return $this->related->of($scope->requester, $date, $localeCode, $scope->case, $access, $values);
    }

    private function canView(CaseShowScope $scope): bool
    {
        return $this->access->canView($scope->requester, $scope->moduleCode, $scope->recordId, $scope->context->date);
    }

    /**
     * 申請書のレコード(業務モジュール)と、今の値・最後の申請・再申請の版の写し・この申請の履歴の行が指す最大の版(どれも求めた所で 1 回だけ読む)。
     *
     * @throws LogicException 申請した案件がレコードを指していないとき(検査制約が止める)
     */
    private function scopeOf(
        AccessContext $context,
        Requester $requester,
        WorkflowCase $case,
        CaseShowSelection $selection,
    ): CaseShowScope {
        $recordId = $case->record_id ?? throw new LogicException("申請した案件 {$case->id} がレコードを指していない");
        $moduleId = (int) Record::query()->whereKey($recordId)->value('record_module_id');
        $moduleCode = (string) RecordModule::query()->whereKey($moduleId)->value('code');
        [$date, $localeCode] = [$context->date, $context->localeCode];
        $histories = self::once(fn () => WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)
            ->whereNotNull('record_revision_id')->orderBy('id')->get(['action', 'record_revision_id']));
        $isSubmission = fn (WorkflowCaseHistory $row) => in_array($row->action, CaseHistories::SUBMISSIONS, true);
        $missing = fn () => new LogicException("申請した案件 {$case->id} の申請の履歴の行が版を指していない");

        return new CaseShowScope(
            $context, $requester, $case, $recordId, $moduleId, $moduleCode, $selection,
            self::once(fn () => $this->display->detail($requester, $recordId, $date, $localeCode)),
            self::once(fn () => $this->revisions->view(
                $requester,
                $histories()->last($isSubmission)->record_revision_id ?? throw $missing(),
                $date,
                $localeCode,
            )),
            self::once(fn () => $histories()->max('record_revision_id') ?? throw $missing()),
        );
    }

    /**
     * 1 回だけ組む値(同じ応答の中で 2 回読まない)。
     *
     * @template T
     *
     * @param  Closure(): T  $build
     * @return Closure(): T
     */
    private static function once(Closure $build): Closure
    {
        $built = [];

        return function () use (&$built, $build) {
            $built = $built === [] ? [$build()] : $built;

            return $built[0];
        };
    }
}
