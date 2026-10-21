<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\CreateRecord;
use App\Actions\Records\Api\UpdateRecord;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowCaseState;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Records\Record;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Master\Api\CurrentDepartment;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\PrincipalContext;
use App\Queries\Workflow\WorkflowRecordHolds;
use App\Support\AccessContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * 申請(新規・一時保存から)(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節・10.1。判定の順と書く順はそこが正本)。
 * レコードの登録(CreateRecord)の 404・403・422 は受け止めずに伝わり、外側ごと巻き戻る(案件とレコードを食い違わせないため)。
 * 鍵の順 = データモデル/直列化と検査.md 4.1、申請の代理で申請者として判定する所 = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節。
 * 業務データの入れ物の口には版の契機を渡し、口が書いた版を履歴の行が指す(設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。
 * フローの「添付を使う」は鍵を取ったフローの行から案件へ写す(設計/ワークフロー/申請の周辺/データモデル.md の 2.1 節・3.4 節)。
 * 添付は口より先に上げない(台帳の鍵を口の中で最後にするため)。一時保存の添付は外す指定を消した残りを口に渡し、移した行を同じトランザクションで消す
 * (設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節・7 節)。
 * 既存のデータからの申請は案件の行を先に書き、レコードの更新の口を持ち主の鍵で呼ぶ(鍵の順 = 案件の行 → 業務データの入れ物の鍵 =
 * 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 4 節・5 節)。
 *
 * @phpstan-type Application array{columns: array<string, mixed>, comment: string|null, revisionId: int,
 *     actor: CaseActor, contact: CaseContact|null}
 */
class ApplyCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseProxyChecks $proxyChecks,
        private CaseInputRules $inputRules,
        private ApplicationBaseDate $baseDates,
        private CaseRouteCopier $copier,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
        private ApplicationRecords $records,
        private CreateRecord $createRecord,
        private CurrentDepartment $currentDepartment,
        private CaseAttachmentRules $attachmentRules,
        private CaseDraftAttachments $attachments,
        private UpdateRecord $updateRecord,
        private RecordApplicationSteps $fromRecord,
        private ContactValidator $contactValidator,
        private SendCaseNotices $notices,
    ) {}

    /**
     * 新規の申請(一時保存を経ない)。
     *
     * @param  string|null  $baseDate  申請基準日の入力(業務処理は既定を持たない = 設計/ワークフロー/ルートの深さ/案件の操作/同時実行と検査.md 13 節)
     * @param  int|null  $applicantUserId  申請者(申請の代理の代理元。null = 自分)
     * @param  CaseContact|null  $contact  連絡(設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.5 節。誤りは 422 contact.*)
     *
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき(業務データの入れ物の 404 も伝わる)
     * @throws AuthorizationException 申請できる人でないとき(ゲストを含む。業務データの入れ物の 403 も伝わる)
     * @throws ValidationException 案件名(name)・コメント(comment)・申請基準日(base_date)・展開(case)・申請者(applicant_user_id)・
     *                             添付(attachments・attachments.<添字>)の誤り、業務データの入れ物の値の誤り
     */
    public function execute(
        AccessContext $context,
        string $flowCode,
        ApplicationInput $input,
        ?string $baseDate,
        ?int $applicantUserId = null,
        ?CaseContact $contact = null,
    ): CaseOperationResult {
        return DB::transaction(function () use ($context, $flowCode, $input, $baseDate, $applicantUserId, $contact) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->sharedLock()->firstOrFail();
            $applicant = $this->proxyChecks->applicantOf($context, $flow->id, $applicantUserId);
            $this->guard->requireApplicable(PrincipalContext::of($context, $applicant), $flow->id);
            $this->guard->permitWithoutCase(WorkflowOperation::Apply);
            $application = $this->prepare($context, $applicant, $flow, $input, $baseDate, $contact);

            $case = WorkflowCase::query()->create(['workflow_flow_id' => $flow->id, 'revision' => 0, ...$application['columns']]);

            return $this->writeNodes($context, $case, $application);
        });
    }

    /**
     * 一時保存からの申請(同じ行が未完了になる)。申請に使うのは一時保存の入力でなく、渡した入力。
     *
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  string|null  $baseDate  申請基準日の入力(一時保存の申請基準日は画面が初期値にするだけ)
     * @param  CaseContact|null  $contact  連絡(誤りは 422 contact.*)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき(業務データの入れ物の 404 も伝わる)
     * @throws AuthorizationException 申請できる人でないとき(業務データの入れ物の 403 も伝わる)
     * @throws ValidationException 改訂番号・表・展開(case)、案件名(name)・コメント(comment)・申請基準日(base_date)・
     *                             添付(attachments・attachments.<添字>・remove_attachment_ids)の誤り、業務データの入れ物の値の誤り
     */
    public function executeFromDraft(
        AccessContext $context,
        int $caseId,
        int $revision,
        ApplicationInput $input,
        ?string $baseDate,
        ?CaseContact $contact = null,
    ): CaseOperationResult {
        return DB::transaction(function () use ($context, $caseId, $revision, $input, $baseDate, $contact) {
            $authorize = fn (WorkflowCase $case) => $this->guard->requireApplicable(
                $this->proxyChecks->applicantJudgeOf($context, $case),
                $case->workflow_flow_id,
            );
            $permit = $this->guard->lockAndPermit(WorkflowOperation::Apply, $context, $caseId, $revision, $authorize);
            $case = $permit->case();
            $application = $this->prepare(
                $context, $permit->processorUserId(), $permit->flow(), $input, $baseDate, $contact, $case,
            );

            $this->writer->advance($case, ['draft_input' => null, ...$application['columns']]);

            return $this->writeNodes($context, $case, $application);
        });
    }

    /**
     * 既存のデータからの申請(判定の順と書く順 = 既存のデータからの申請.md の 4 節。申請者の決め方は新規の申請と同じ)。
     * 添付は渡したファイルだけを付け、外す指定は読まない(設計/ワークフロー/申請の周辺/添付/README.md の 2 節)。
     *
     * @param  string|null  $recordVersion  申請の画面を開いたときのレコードの版(null は空文字として比べる = この経路はいつも版を比べる)
     * @param  string|null  $baseDate  申請基準日の入力
     * @param  int|null  $applicantUserId  申請者(申請の代理の代理元。null = 自分)
     * @param  CaseContact|null  $contact  連絡(新規の申請と同じ。誤りは 422 contact.*)
     *
     * @throws ModelNotFoundException<WorkflowFlow|Record> フローが無い・レコードが無い・その業務モジュールの親の無いレコードでないとき
     * @throws AuthorizationException 申請者が既存のデータからの申請をできないとき(業務データの入れ物の 403 も伝わる)
     * @throws ValidationException 持ち主がある・同じレコードへの別の申請と競合した・開いた後に更新された(record)、
     *                             案件名・コメント・申請基準日・展開・申請者・添付の誤り、業務データの入れ物の値の誤り
     * @throws QueryException 案件の行の挿入で、捕まえる 2 つのほかの制約に違反したとき
     */
    public function executeFromRecord(
        AccessContext $context,
        string $flowCode,
        int $recordId,
        ?string $recordVersion,
        ApplicationInput $input,
        ?string $baseDate,
        ?int $applicantUserId = null,
        ?CaseContact $contact = null,
    ): CaseOperationResult {
        return DB::transaction(function () use (
            $context, $flowCode, $recordId, $recordVersion, $input, $baseDate, $applicantUserId, $contact,
        ) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->sharedLock()->firstOrFail();
            $applicantId = $this->proxyChecks->applicantOf($context, $flow->id, $applicantUserId);
            $applicant = PrincipalContext::of($context, $applicantId);
            $moduleCode = $this->records->moduleCodeOf($flow);
            $this->fromRecord->confirm($applicant, $flow->id, $moduleCode, $recordId);
            $this->guard->permitWithoutCase(WorkflowOperation::Apply);
            [$name, $comment] = $this->inputRules->nameAndComment($input->name, $input->comment);
            $contact = $this->contactValidator->validated($contact, $context);
            [$date, $versionId] = $this->baseDates->resolveFromRecord($applicant, $flow->id, $baseDate);
            $this->attachmentRules->rejectFiles(WorkflowOperation::Apply, $flow, $input->attachFiles);
            $columns = $this->caseColumns($applicant, $applicantId, $flow, $date, $versionId);
            // 既定の案件名は更新の後の値で決めるので、挿入の時はフロー名で置き、計画を書くときに直す。
            $case = $this->fromRecord->insertCase($flow->id, $recordId, [...$columns, 'name' => $name ?? $flow->name]);
            $actor = CaseActor::forProcessor($applicantId, $context);
            $origin = $this->records->origin(WorkflowAction::Apply, $this->records->applyNodeName($versionId), $actor);
            $holder = WorkflowRecordHolds::holderOf($case->id);
            [$record, $version] = [$input->recordInput(), $recordVersion ?? ''];
            $this->updateRecord->execute($applicant, $moduleCode, $recordId, $record, $version, $holder, $origin);
            $revisionId = $this->records->revisionOf($recordId);
            $application = [
                'columns' => [],
                'comment' => $comment,
                'revisionId' => $revisionId,
                'actor' => $actor,
                'contact' => $contact,
            ];
            $name ??= $this->records->defaultName($flow, $recordId);

            return $this->writeNodes($context, $case, $application, ['name' => $name]);
        });
    }

    /**
     * 判定の順の 7〜8 と案件番号(設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節)。申請基準日の規則・レコードの登録・担当組織は申請者で決める
     * (代理先が申請しても案件の値を代理元のものにするため = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.5 節)。
     *
     * @param  int  $applicantUserId  申請者(処理者)
     * @param  WorkflowCase|null  $draft  一時保存からの申請の一時保存(新規の申請は null)
     * @return Application
     */
    private function prepare(
        AccessContext $context,
        int $applicantUserId,
        WorkflowFlow $flow,
        ApplicationInput $input,
        ?string $baseDate,
        ?CaseContact $contact,
        ?WorkflowCase $draft = null,
    ): array {
        $applicant = PrincipalContext::of($context, $applicantUserId);
        [$name, $comment] = $this->inputRules->nameAndComment($input->name, $input->comment);
        $contact = $this->contactValidator->validated($contact, $context);
        [$date, $versionId] = $this->baseDates->resolve($applicant, $flow->id, $baseDate);
        $actor = CaseActor::forProcessor($applicantUserId, $context);
        $origin = $this->records->origin(WorkflowAction::Apply, $this->records->applyNodeName($versionId), $actor);
        $moving = $this->draftAttachmentsToMove($flow, $draft, $input, $actor);
        $moduleCode = $this->records->moduleCodeOf($flow);
        $record = $input->recordInput($this->attachments->uploadInputs($moving));
        $recordId = $this->createRecord->execute($applicant, $moduleCode, $record, $origin);
        $this->attachments->forget($moving);
        $revisionId = $this->records->revisionOf($recordId);
        $name ??= $this->records->defaultName($flow, $recordId);

        return [
            'columns' => [
                ...$this->caseColumns($applicant, $applicantUserId, $flow, $date, $versionId),
                'name' => $name,
                'record_id' => $recordId,
            ],
            'comment' => $comment,
            'revisionId' => $revisionId,
            'actor' => $actor,
            'contact' => $contact,
        ];
    }

    /**
     * 申請で書く案件の行の列のうち、レコードと案件名に依らないもの(案件番号を採る。担当組織 = 申請者の申請基準日のカレント組織)。
     *
     * @param  AccessContext  $applicant  申請者(代理のときは代理元)
     * @return array<string, mixed>
     */
    private function caseColumns(
        AccessContext $applicant,
        int $applicantUserId,
        WorkflowFlow $flow,
        CarbonImmutable $date,
        int $versionId,
    ): array {
        return [
            'state' => WorkflowCaseState::Active,
            'case_number' => (int) DB::scalar("select nextval('workflow_case_number_seq')"),
            'user_id' => $applicantUserId,
            'department_id' => $this->currentDepartment->departmentIdOn($applicantUserId, $applicant->selectedDepartmentId, $date),
            'applied_at' => now(),
            'workflow_flow_version_id' => $versionId,
            'base_date' => $date->toDateString(),
            'is_attachment_enabled' => $flow->is_attachment_enabled,
        ];
    }

    /**
     * 判定の順の 7 の添付の規則(設計/ワークフロー/申請の周辺/添付/README.md の 3.2 節)と、外す指定の書き込み(口を呼ぶ前)。
     * 新規の申請は外す相手が無いので、外す指定を読まない。
     *
     * @return Collection<int, WorkflowCaseDraftAttachment> 一時保存から移す行
     *
     * @throws ValidationException 添付(attachments・remove_attachment_ids)の誤り
     */
    private function draftAttachmentsToMove(
        WorkflowFlow $flow,
        ?WorkflowCase $draft,
        ApplicationInput $input,
        CaseActor $actor,
    ): Collection {
        $this->attachmentRules->rejectFiles(WorkflowOperation::Apply, $flow, $input->attachFiles);

        if ($draft === null) {
            return new Collection;
        }

        $removals = $this->attachmentRules->removals($draft, $input->removeAttachmentIds, $actor->executorUserId);
        $remaining = $this->attachmentRules->remainingAfter($flow, $draft, $removals);
        $this->attachments->remove($removals);

        return $remaining;
    }

    /**
     * 版のルートを写し、申請のノードから計画して書く(案件の行は最初に書いてあるので、改訂番号は進めない = 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 9 節の最後の箇条)。
     *
     * @param  Application  $application
     * @param  array<string, mixed>  $columns  案件の行にほかに書く列(既存のデータからの申請の案件名)
     */
    private function writeNodes(
        AccessContext $context,
        WorkflowCase $case,
        array $application,
        array $columns = [],
    ): CaseOperationResult {
        $copied = $this->copier->copy($case, $case->workflow_flow_version_id ?? throw new LogicException("案件 {$case->id} に写した版が無い"));
        $applyNodes = array_filter($copied, fn (WorkflowCaseNode $node) => $node->kind === WorkflowNodeKind::Apply);
        $applyNode = reset($applyNodes) ?: throw new LogicException("案件 {$case->id} に申請のノードが無い");
        $actor = $application['actor'];
        $plan = $this->planner->plan(CasePlanRequest::by($actor, $context, $case, $applyNode->id, WorkflowAction::Apply));

        [$comment, $revision] = [$application['comment'], $application['revisionId']];

        $result = $this->writer->writePlan(
            $plan, $actor, $comment, $revision, columns: $columns, advanceRevision: false,
        );

        $this->notices->execute($result, $application['contact'], $context->date);

        return $result;
    }
}
