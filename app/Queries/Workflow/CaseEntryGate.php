<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Support\AccessContext;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * ワークフローの書き込みの入口の判定(docs の 設計/ワークフロー/案件の操作/操作.md 3.1 の 1): FormRequest の authorize が、入力の封筒の検証(422)より前に
 * ルートの引数が指すものを引き、404 → 403 の順に例外を投げる(設計/業務データの入れ物/認可とメニュー/README.md 2.2 と同じ規則)。業務処理はロックの下で同じ順をもう一度確かめる
 * (状態 × 役割の表と改訂番号は業務処理だけが見る = 一時保存のルートの書き込みが一時保存でない案件を指すときは、業務処理の 422 case)。
 * 判定そのものは読み取りの 1 か所(閲覧 = {@see CaseVisibility}、申請できる人 = {@see ApplicableFlows}・{@see RecordApplyFlows}、update = {@see CaseOperationDecider::isUpdatable()})を呼ぶ。鍵を取らない。
 */
class CaseEntryGate
{
    /** 申請できる人でないときの 403 の文言(正。申請・一時保存の画面の 403 もこの口から投げ、業務処理の 403 = CaseOperationErrors::NOT_APPLICABLE はこれを参照する) */
    public const NOT_APPLICABLE_MESSAGE = 'このフローを申請できません';

    public function __construct(
        private ApplicableFlows $applicableFlows,
        private CaseVisibility $visibility,
        private CaseOperationDecider $decider,
        private RecordApplyFlows $recordApplyFlows,
    ) {}

    /**
     * 新規の申請・一時保存: フローが無い 404 → 申請できる人でない 403。
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     * @throws AuthorizationException 申請できる人でないとき(ゲストを含む)
     */
    public function authorizeFlow(AccessContext $context, string $flowCode): void
    {
        $flow = WorkflowFlow::query()->where('code', $flowCode)->firstOrFail();
        $this->requireApplicable($context, $flow->id);
    }

    /**
     * 既存のデータからの申請(画面の GET と申請の入口): フローが無い 404 → 自分か代理元のどれも既存のデータからの申請をできない 403
     * (業務モジュールの単位の判定なので、レコードの有無を漏らさない = 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節)。
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     * @throws AuthorizationException 既存のデータからの申請をできないとき(ゲストを含む)
     */
    public function authorizeRecordApply(AccessContext $context, string $flowCode): void
    {
        $flow = WorkflowFlow::query()->where('code', $flowCode)->firstOrFail();

        if (! $this->recordApplyFlows->allowsAny(Requester::fromAccessContext($context), $flow->id, $context->date)) {
            throw new AuthorizationException(self::NOT_APPLICABLE_MESSAGE);
        }
    }

    /**
     * 申請対象からの申請の画面の GET: フローが無い 404 → 自分か代理元のどれもこの経路で申請できない 403(レコードの有無を漏らさない
     * = 設計/ワークフロー/申請の周辺/既存のデータからの申請/申請対象からの申請.md の 9.3 節)。
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     * @throws AuthorizationException 申請対象からの申請をできないとき(ゲストを含む)
     */
    public function authorizeApplicationTargetApply(AccessContext $context, string $flowCode): void
    {
        $flow = WorkflowFlow::query()->where('code', $flowCode)->firstOrFail();
        $requester = Requester::fromAccessContext($context);

        if (! $this->recordApplyFlows->allowsAnyForTarget($requester, $flow->id, $context->date)) {
            throw new AuthorizationException(self::NOT_APPLICABLE_MESSAGE);
        }
    }

    /**
     * 既にある案件の書き込みのうち、403 を求めないもの(処理 6 つと一時保存の削除): 案件が無い・閲覧できない 404。
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     */
    public function authorizeCase(AccessContext $context, int $caseId): void
    {
        $this->visibleCase($context, $caseId);
    }

    /**
     * 一時保存の上書き・一時保存からの申請: 案件が無い・閲覧できない 404 → 申請できる人でない 403。
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException 申請できる人でないとき
     */
    public function authorizeDraftWrite(AccessContext $context, int $caseId): void
    {
        $this->requireApplicable($context, $this->visibleCase($context, $caseId)->workflow_flow_id);
    }

    /**
     * 再申請: 案件が無い・閲覧できない 404 → コンテンツの業務モジュールの update を許されない 403。
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws AuthorizationException update を許されないとき
     */
    public function authorizeReapply(AccessContext $context, int $caseId): void
    {
        $this->requireUpdatable(Requester::fromAccessContext($context), $context->date, $this->visibleCase($context, $caseId)->workflow_flow_id);
    }

    /**
     * 再申請の 403 の 1 か所(入口・再申請の画面の GET・業務処理が呼ぶ): 判定する人がそのフローのコンテンツの業務モジュールの update を許されるか。判定する人は
     * 呼び手が渡す(3a = 今の利用者。3b の代理は代理元 = 3a の動きは変えない)。
     *
     * @throws AuthorizationException 許されないとき
     */
    public function requireUpdatable(Requester $requester, DateTimeInterface $date, int $flowId): void
    {
        if (! $this->decider->isUpdatable($requester, $date, $flowId)) {
            throw new AuthorizationException(RecordFormQuery::NOT_ALLOWED_MESSAGE);
        }
    }

    /**
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     */
    private function visibleCase(AccessContext $context, int $caseId): WorkflowCase
    {
        return $this->visibility->visible(WorkflowCase::query()->whereKey($caseId), Requester::fromAccessContext($context), $context->date)->firstOrFail();
    }

    /**
     * @throws AuthorizationException 申請できる人でないとき
     */
    private function requireApplicable(AccessContext $context, int $flowId): void
    {
        if (! $this->applicableFlows->isApplicable(Requester::fromAccessContext($context), $context->date, $flowId)) {
            throw new AuthorizationException(self::NOT_APPLICABLE_MESSAGE);
        }
    }
}
