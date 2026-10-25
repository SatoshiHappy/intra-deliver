<?php

namespace App\Queries\Workflow;

use App\Enums\Records\RecordModuleAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordShowPanelQuery;
use App\Support\AccessContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 申請・一時保存・再申請の画面(docs の 設計/ワークフロー/画面/申請と案件.md 3 節の Workflow/Apply の Props。
 * 開けないときの判定の順 = 画面/README.md 1.1、申請基準日 = ルートの深さ/画面.md 5 節)。
 * 403 の判定は書き込みの入口と同じ {@see CaseEntryGate} の口を使う(画面と書き込みで食い違わない)。再申請の一時保存の 404 は画面だけが持つ条件なので、
 * 状態つきの閲覧を口より先に引く。業務データの入れ物の主体の判定をしない口(初期値)は、これらの判定の後にだけ呼ぶ(業務データとの接続/写しとつながる申請.md 9 節)。
 *
 * @phpstan-import-type Next from ApplicationNext
 * @phpstan-import-type ApplyAttachment from ApplyAttachments
 * @phpstan-import-type ApplicationTargetValueSet from ApplicationTargetValues
 *
 * @phpstan-type ApplyForm array{mode: string, flow: array{code: string, name: string, description: string|null}, case: array{id: int, number: string|null, revision: int}|null, module: array<string, mixed>, headings: list<array{id: int|null, name: string|null, fieldCodes: list<string>}>, initial: array{name: string|null, comment: string|null, values: array<string, mixed>, details: array<string, list<array<string, mixed>>>}|null, titleField: string|null, recordId: int|null, baseDate: string, baseDateRange: array{min: string|null, max: string|null}, next: Next, nameMaxLength: int, commentMaxLength: int, attachment: ApplyAttachment,
 *     recordVersion: string|null, recordTitle: string|null, targetField: string|null,
 *     targetValues: ApplicationTargetValueSet|null, targetEntry: array{module: string, record: int}|null}
 */
class ApplicationFormQuery
{
    public function __construct(
        private CaseEntryGate $gate,
        private CaseVisibility $visibility,
        private CaseOperationDecider $decider,
        private RecordFormQuery $forms,
        private RecordShowPanelQuery $panels,
        private ApplicationNext $next,
        private RecordApplyFlows $recordApplyFlows,
        private ApplyAttachments $attachments,
        private RecordApplyTargets $recordTargets,
        private RecordDisplayQuery $display,
        private ApplicationTargetValues $applicationTargetValues,
    ) {}

    /**
     * 新規の申請の画面(initial は null = 既定値で始める)。
     *
     * @param  mixed  $baseDate  クエリ base_date(申請基準日を変えたときの部分再読み込み)
     * @return ApplyForm
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     * @throws AuthorizationException 申請できる人でないとき(ゲストを含む)
     */
    public function create(AccessContext $context, string $flowCode, mixed $baseDate = null): array
    {
        $this->gate->authorizeFlow($context, $flowCode);
        $requester = Requester::fromAccessContext($context);
        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->where('code', $flowCode)->firstOrFail();
        $module = $this->forms->definition($requester, $this->moduleOf($flow)->code, RecordModuleAction::Create, $context->date);

        return $this->newForm($context, $context, $flow, $module, $baseDate);
    }

    /**
     * 新規の申請の画面の形(申請対象からの申請の画面 {@see ApplicationTargetForm} も使う)。申請基準日と次の処理対象者は申請者で引く。
     *
     * @param  AccessContext  $applicant  申請者(代理のときは代理元)
     * @param  array<string, mixed>  $module  申請者で create を判定して引いた定義
     * @return ApplyForm
     */
    public function newForm(
        AccessContext $context,
        AccessContext $applicant,
        WorkflowFlow $flow,
        array $module,
        mixed $baseDate,
    ): array {
        $date = $this->applicationDate($applicant, $flow, [$baseDate]);

        return $this->form('new', $flow, null, $module, null, null, $date, $context, $this->attachments->forNew($flow));
    }

    /**
     * 一時保存の画面(initial = 一時保存の入力を今の定義で読んだ値 = RecordFormQuery::draftValues と、案件名・コメント)。見えるが一時保存でない案件なら null
     * (別のタブで申請した後など。入口が詳細へ移してトーストを出す = 画面/README.md 1.1)。
     *
     * @param  mixed  $baseDate  クエリ base_date(申請基準日を変えたときの部分再読み込み)
     * @return ApplyForm|null
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・見えないとき
     * @throws AuthorizationException 申請できる人でないとき
     */
    public function draft(AccessContext $context, int $caseId, mixed $baseDate = null): ?array
    {
        $requester = Requester::fromAccessContext($context);
        $case = $this->visibility->visible(WorkflowCase::query()->whereKey($caseId), $requester, $context->date)->firstOrFail();
        $this->gate->authorizeDraftWrite($context, $case->id);

        if ($case->state !== WorkflowCaseState::Draft) {
            return null;
        }

        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->findOrFail($case->workflow_flow_id);
        $moduleCode = $this->moduleOf($flow)->code;
        $module = $this->forms->definition($requester, $moduleCode, RecordModuleAction::Create, $context->date);
        $input = $case->draft_input ?? [];
        $read = $this->forms->draftValues($requester, $moduleCode, $this->arrayOf($input['values'] ?? null), $this->arrayOf($input['details'] ?? null), $context->date, $context->localeCode);
        $initial = ['name' => $this->stringOf($input['name'] ?? null), 'comment' => $this->stringOf($input['comment'] ?? null), 'values' => $read['values'], 'details' => $read['details']];

        $date = $this->applicationDate($context, $flow, [$baseDate, $input['base_date'] ?? null]);

        $attachment = $this->attachments->forDraft($flow, $case, $context->userId);

        return $this->form('draft', $flow, ['id' => $case->id, 'number' => null, 'revision' => $case->revision], $module, $initial, null, $date, $context, $attachment);
    }

    /**
     * 再申請の画面(initial = 今のレコードの値 = RecordFormQuery::editValues と、今の案件名。コメントは空)。再申請ができない状態・役割なら null
     * (判定 = 詳細の can の reapply。古いリンクから来た人を行き止まりにしない = 画面/README.md 1.1)。
     *
     * @return ApplyForm|null
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・見えない・一時保存のとき
     * @throws AuthorizationException 業務モジュールの update を許されないとき
     */
    public function reapply(AccessContext $context, int $caseId): ?array
    {
        $requester = Requester::fromAccessContext($context);
        $case = $this->visibility->visible(WorkflowCase::query()->whereKey($caseId)->where('state', '<>', WorkflowCaseState::Draft), $requester, $context->date)->firstOrFail();
        $this->gate->authorizeReapply($context, $case->id);
        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->findOrFail($case->workflow_flow_id);
        $moduleCode = $this->moduleOf($flow)->code;
        $module = $this->forms->definition($requester, $moduleCode, RecordModuleAction::Update, $context->date);
        $situation = $this->decider->situationsOf($requester, $context->date, [$case])[$case->id];

        if (! $this->decider->detailCanOf($requester, $context->date, $situation)['reapply']) {
            return null;
        }

        $recordId = $case->record_id ?? throw new LogicException("申請した案件 {$case->id} がレコードを指していない");
        $read = $this->forms->editValues($requester, $moduleCode, $recordId, $context->date, $context->localeCode);
        $initial = ['name' => $case->name, 'comment' => null, 'values' => $read['values'], 'details' => $read['details']];
        $number = CaseNumbers::format($case->case_number ?? throw new LogicException("申請した案件 {$case->id} に案件番号が無い"));

        $date = [
            'baseDate' => ($case->base_date ?? throw new LogicException("申請した案件 {$case->id} に申請基準日が無い"))->format(ApplicationBaseDates::FORMAT),
            'baseDateRange' => ['min' => null, 'max' => null],
            'next' => $this->next->ofCase($context, $case),
        ];

        $attachment = $this->attachments->forReapply($context, $flow, $case, $moduleCode, $recordId);

        return $this->form('reapply', $flow, ['id' => $case->id, 'number' => $number, 'revision' => $case->revision], $module, $initial, $recordId, $date, $context, $attachment);
    }

    /**
     * 既存のデータからの申請の画面(判定の順 = 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節。initial = 今のレコードの値と、開いたときの版
     * recordVersion)。持ち主があれば null(入口がレコードの詳細へ戻す)。定義・初期値・申請基準日は既定の申請者({@see self::recordApplicant()})で引く。
     *
     * @param  mixed  $baseDate  クエリ base_date(申請基準日を変えたときの部分再読み込み)
     * @return ApplyForm|null
     *
     * @throws ModelNotFoundException<WorkflowFlow|Record> フローが無い・レコードが無い・その業務モジュールの親の無いレコードでない・判定を求める者がいないとき
     * @throws AuthorizationException 自分か代理元のどれも既存のデータからの申請をできないとき(ゲストを含む)
     */
    public function fromRecord(AccessContext $context, string $flowCode, int $recordId, mixed $baseDate = null): ?array
    {
        $this->gate->authorizeRecordApply($context, $flowCode);
        $flow = WorkflowFlow::query()->with('workflowContent.recordModule')->where('code', $flowCode)->firstOrFail();
        $moduleCode = $this->moduleOf($flow)->code;
        $principalId = $this->recordApplicant($context, $flow->id, $moduleCode, $recordId);
        $applicant = $principalId === null ? $context : PrincipalContext::of($context, $principalId);
        $requester = Requester::fromAccessContext($applicant);

        if ($this->recordTargets->isHeld($recordId)) {
            return null;
        }

        $module = $this->forms->definition($requester, $moduleCode, RecordModuleAction::Update, $context->date);
        $read = $this->forms->editValues($requester, $moduleCode, $recordId, $context->date, $context->localeCode);
        $initial = ['name' => null, 'comment' => null, 'values' => $read['values'], 'details' => $read['details']];
        $date = $this->applicationDate($applicant, $flow, [$baseDate]);
        $attachment = $this->attachments->forRecord($applicant, $flow, $moduleCode, $recordId);
        $form = $this->form('record', $flow, null, $module, $initial, $recordId, $date, $context, $attachment);
        $applicationTarget = $this->applicationTargetValues->applicationTargetOf($flow);
        $copied = $applicationTarget === null ? null : $this->applicationTargetValues->valuesOfRecord(
            $requester, $applicationTarget, $module, $read['values'], $context->date, $context->localeCode,
        );

        return [...$form, 'recordVersion' => $read['version'], 'recordTitle' => $this->display->titleOf($recordId),
            'targetValues' => $copied];
    }

    /**
     * 既存のデータからの申請の画面の既定の申請者 = 判定を求める者。候補は作成者に絞られ多くて 1 人なので、クエリ applicant によらずその人になる
     * (docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節、設計/ワークフロー/案件の運用/画面/案件の画面.md の 6.1 節)。
     *
     * @return int|null 選ぶ代理元(自分なら null)
     *
     * @throws ModelNotFoundException<Record> 候補がいない・候補がレコードを閲覧できない・その業務モジュールの親の無いレコードでないとき
     */
    public function recordApplicant(AccessContext $context, int $flowId, string $moduleCode, int $recordId): ?int
    {
        $requester = Requester::fromAccessContext($context);
        $candidates = $this->recordApplyFlows->applicantIds($requester, $flowId, $context->date);
        $applicantId = $this->recordTargets
            ->requireApplicant($requester, $candidates, $moduleCode, $recordId, $context->date);

        return $applicantId === $context->userId ? null : $applicantId;
    }

    /**
     * @param  array{id: int, number: string|null, revision: int}|null  $case
     * @param  array<string, mixed>  $module
     * @param  array{name: string|null, comment: string|null, values: array<string, mixed>, details: array<string, list<array<string, mixed>>>}|null  $initial
     * @param  int|null  $recordId  書くレコード(再申請・既存のデータからの申請。参照の候補から除く = 設計/業務データの入れ物/画面/README.md 4 節)
     * @param  array{baseDate: string, baseDateRange: array{min: string|null, max: string|null}, next: Next}  $date  申請基準日と次の処理対象者
     * @param  ApplyAttachment  $attachment  添付の欄(設計/ワークフロー/申請の周辺/添付/README.md の 5 節)
     * @return ApplyForm
     */
    private function form(string $mode, WorkflowFlow $flow, ?array $case, array $module, ?array $initial, ?int $recordId, array $date, AccessContext $context, array $attachment): array
    {
        return [
            'mode' => $mode,
            'flow' => ['code' => $flow->code, 'name' => $flow->name, 'description' => $flow->description],
            'case' => $case,
            'module' => $module,
            'headings' => $this->panels->headings($this->moduleOf($flow)->id),
            'initial' => $initial,
            'titleField' => $this->titleFieldOf($module),
            'recordId' => $recordId,
            'nameMaxLength' => WorkflowCase::NAME_MAX,
            'commentMaxLength' => WorkflowCaseHistory::COMMENT_MAX,
            'attachment' => $attachment,
            'recordVersion' => null,
            'recordTitle' => null,
            'targetField' => $this->applicationTargetValues->applicationTargetOf($flow)['field'] ?? null,
            'targetValues' => null,
            'targetEntry' => null,
            ...$date,
        ];
    }

    /**
     * 新規・一時保存の申請基準日(選び方 = ルートの深さ/画面.md 5 節。選べる範囲の外の候補も捨てない = 欄の日付と next の日付を一致させるため)と、
     * その日の次の処理対象者・選べる範囲。
     *
     * @param  list<mixed>  $candidates  クエリの値・一時保存の値の順
     * @return array{baseDate: string, baseDateRange: array{min: string|null, max: string|null}, next: Next}
     */
    private function applicationDate(AccessContext $context, WorkflowFlow $flow, array $candidates): array
    {
        $dates = array_values(array_filter(array_map(fn (mixed $candidate) => ApplicationBaseDates::parse($candidate), $candidates)));
        $baseDate = $dates[0] ?? $context->date;

        return [
            'baseDate' => $baseDate->format(ApplicationBaseDates::FORMAT),
            'baseDateRange' => $this->next->baseDateRange($flow->id, $context->date),
            'next' => $this->next->ofFlow($context, $flow->id, $baseDate),
        ];
    }

    /**
     * 表示の項目のコード(定義の fields のうち isTitle のもの。無ければ null。案件名の既定の案内に使う)。
     *
     * @param  array<string, mixed>  $module
     */
    private function titleFieldOf(array $module): ?string
    {
        foreach (is_array($module['fields'] ?? null) ? $module['fields'] : [] as $field) {
            if (is_array($field) && ($field['isTitle'] ?? false) === true && is_string($field['code'] ?? null)) {
                return $field['code'];
            }
        }

        return null;
    }

    /**
     * フローのコンテンツの業務モジュール(読み込んだ関係から。外部キーで必ずある)。
     *
     * @throws LogicException 関係を読み込んでいない・行が無いとき
     */
    private function moduleOf(WorkflowFlow $flow): RecordModule
    {
        return $flow->workflowContent->recordModule ?? throw new LogicException("フロー {$flow->code} のコンテンツの業務モジュールを読み込んでいない");
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    private function stringOf(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
