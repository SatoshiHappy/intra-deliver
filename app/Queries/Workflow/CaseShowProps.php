<?php

namespace App\Queries\Workflow;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Queries\Authz\Api\AuthorizationReuse;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordRevisionQuery;
use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Support\AccessContext;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 申請の詳細の props を組む 1 か所(docs の 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2 節・6 節、
 * 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.2 節・1.4 節)。レコードの中身は申請の閲覧の判定で守る
 * (一時保存でない申請を閲覧できる人には申請の経路の RecordViewAccess がいつも真なので、レコードの閲覧を重ねて判定しない)。
 *
 * @phpstan-import-type RelatedGroup from RelatedCases
 */
class CaseShowProps
{
    /** 申請の層のうち {@see CaseDetailQuery} が組むもの */
    public const DETAIL_LAYER = [
        'case', 'nodes', 'edges', 'councils', 'histories', 'can', 'nodeActions', 'pullBackNodes', 'casePrincipal',
        'proxyNote', 'canAttachOnWithdraw', 'attachmentLimits', 'operation', 'confirmations', 'commentMaxLength',
        'links',
    ];

    /**
     * 申請の層(つながる申請と、ボタン「複製」を出すか = 設計/ワークフロー/申請の周辺/既存のデータからの申請/案件からの複製/README.md の 12.2 節を含む)。
     */
    public const CASE_LAYER = [...self::DETAIL_LAYER, 'related', 'canCopy'];

    /** レコードの層(申請書のレコードの中身。サイドパネルと関連レコードはレコードの詳細と同じ口で読む) */
    public const RECORD_LAYER = [
        'content', 'headings', 'panel', 'sections', 'panelContent', 'relatedRecords', 'relatedRecordRows',
        'relatedRecordForm',
    ];

    /** 最初の応答に入らない props(部分の再読み込みで求めたときだけ組む) */
    public const OPTIONAL = ['relatedRecordForm'];

    public function __construct(
        private CaseDetailQuery $detail,
        private RecordDisplayQuery $display,
        private RecordRevisionQuery $revisions,
        private RecordShowPanelQuery $panels,
        private CaseRecordPanel $panel,
        private CaseRelatedRecords $relatedRecords,
        private RelatedCases $related,
        private CaseCopyability $copyability,
        private AuthorizationReuse $reuse,
    ) {}

    /**
     * 最初の組み立てを判定の使い回しのまとまりで囲み、組み立ての後に申請の閲覧だけを使い回さない区間で判定し直す(囲み方 = docs の
     * 設計/業務データの入れ物/レコードの共通機能/詳細の画面/申請の詳細.md の 4.2 節)。
     *
     * @return array<string, mixed> 求められた props
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できない・一時保存のとき(組み立ての後の 2 回目の判定で見えなくなったときを含む)
     */
    public function of(AccessContext $context, int $caseId, CaseShowSelection $selection): array
    {
        $requester = Requester::fromAccessContext($context);
        $props = $this->reuse->within(fn () => $this->assemble($context, $requester, $caseId, $selection));

        return $this->reuse->without(function () use ($context, $requester, $caseId, $props) {
            $this->detail->visibleCase($requester, $context, $caseId);

            return $props;
        });
    }

    /**
     * 最初の組み立て(申請の閲覧の判定 = {@see CaseDetailQuery::visibleCase()} の後に、求められた props だけを組む)。
     *
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できない・一時保存のとき
     */
    private function assemble(
        AccessContext $context,
        Requester $requester,
        int $caseId,
        CaseShowSelection $selection,
    ): array {
        $case = $this->detail->visibleCase($requester, $context, $caseId);
        $scope = $this->scopeOf($context, $requester, $case, $selection);
        $props = $selection->wantsAny(self::DETAIL_LAYER) ? $this->detail->show($context, $caseId) : [];

        foreach ($this->builders($scope) as $key => $build) {
            if ($selection->wants($key, in_array($key, self::OPTIONAL, true))) {
                $props[$key] = $build();
            }
        }

        if ($selection->wants('canCopy')) {
            $props['canCopy'] = $this->copyability->canCopy($context, (int) $case->workflow_flow_id);
        }

        return $props;
    }

    /**
     * レコードの層とつながる申請(サイドパネルは口が読む時に閲覧を判定し、関連レコードの口の呼び手の判定は入口の申請の閲覧の判定で満たす)。
     *
     * @return array<string, Closure(): mixed>
     */
    private function builders(CaseShowScope $scope): array
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
            'related' => fn () => $this->relatedOf($scope),
        ];
    }

    /**
     * つながる申請(申請書のレコードからいつもたどり、申請対象は申請書の今の値から引く = 設計/ワークフロー/業務データとの接続/写しとつながる申請.md の 6 節)。
     *
     * @return list<RelatedGroup>
     */
    private function relatedOf(CaseShowScope $scope): array
    {
        $values = ($scope->current)()['record']['values'];
        [$date, $localeCode] = [$scope->context->date, $scope->context->localeCode];

        return $this->related->of($scope->requester, $date, $localeCode, $scope->case, $values);
    }

    /**
     * 申請書のレコード(業務モジュール)と、今の値・この申請の履歴の行が指す最大の版(どれも求めた所で 1 回だけ読む)。
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
        $missing = fn () => new LogicException("申請した案件 {$case->id} の申請の履歴の行が版を指していない");

        return new CaseShowScope(
            $context, $requester, $case, $recordId, $moduleId, $moduleCode, $selection,
            self::once(fn () => $this->display->detail($requester, $recordId, $date, $localeCode)),
            self::once(fn () => (int) (WorkflowCaseHistory::query()->where('workflow_case_id', $case->id)
                ->max('record_revision_id') ?? throw $missing())),
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
