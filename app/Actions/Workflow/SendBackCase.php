<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseNode;
use App\Queries\Workflow\CaseActor;
use App\Queries\Workflow\SendBackCandidates;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 差戻し(docs の 設計/ワークフロー/ルートの深さ/案件の操作/操作.md 7 節・10.4。判定の順と戻し方はそこが正本)。
 * 差戻し先の候補は {@see SendBackCandidates} の 1 か所(画面の候補と業務処理が食い違わないため)。
 */
class SendBackCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private SendBackCandidates $candidates,
        private CaseRoutePlanner $planner,
        private CaseWriter $writer,
        private ContactValidator $contactValidator,
        private CaseStepAttachments $attachments,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(差戻し元。案件のノードの id)
     * @param  int  $sendBackNodeId  差戻し先(案件のノードの id)
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     * @param  CaseContact|null  $contact  連絡(規則 = {@see ContactValidator}。誤りは 422 contact.user_ids・contact.message)
     * @param  list<UploadedFile>  $attachFiles  付ける添付(設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節の attachments。空 = 付けない)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・ノード・表・戻す相手(case)、差戻し先(send_back_node_id)・コメント(comment)の誤り、
     *                             処理担当者(principal_user_id)の誤り、
     *                             添付(attachments・attachments.<添字>)・添付を付けるレコードの持ち主(record)の誤り
     * @throws RuntimeException 添付の実体を書けなかったとき
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        int $sendBackNodeId,
        ?string $comment,
        ?int $principalUserId = null,
        ?CaseContact $contact = null,
        array $attachFiles = [],
    ): CaseOperationResult {
        return DB::transaction(function () use (
            $context, $caseId, $revision, $nodeId, $sendBackNodeId, $comment, $principalUserId, $contact, $attachFiles,
        ) {
            $permit = $this->guard->lockAndPermit(
                WorkflowOperation::SendBack,
                $context,
                $caseId,
                $revision,
                nodeId: $nodeId,
                principalUserId: $principalUserId,
            );
            $this->requireCandidate($permit->node()->id, $sendBackNodeId);
            $comment = $this->inputRules->comment($comment);
            $contact = $this->contactValidator->validated($contact, $context);
            $node = $permit->node();
            $flow = $this->attachments->rejectFiles(WorkflowOperation::SendBack, $permit, $node, $attachFiles);
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $plan = $this->planner->plan(CasePlanRequest::by($actor, $context, $permit->case(), $nodeId, WorkflowAction::SendBack, sendBackNodeId: $sendBackNodeId));
            $action = WorkflowAction::SendBack;
            $revisionId = $this->attachments->attach($permit, $actor, $context, $action, $node, $flow, $attachFiles);

            $result = $this->writer->writePlan($plan, $actor, $comment, $revisionId);

            $this->notices->execute($result, $contact, $context->date);

            return $result;
        });
    }

    /**
     * 差戻し先が候補にあるか(設計/ワークフロー/案件の運用/案件の操作/同時実行と検査.md の 13 節)。
     *
     * @throws ValidationException 候補が空・候補に無いとき(send_back_node_id)
     */
    private function requireCandidate(int $nodeId, int $sendBackNodeId): void
    {
        $candidateIds = array_map(fn (WorkflowCaseNode $node) => $node->id, $this->candidates->of($nodeId));

        if ($candidateIds === []) {
            throw ValidationException::withMessages(['send_back_node_id' => [CaseOperationErrors::SEND_BACK_NOT_ALLOWED]]);
        }

        if (! in_array($sendBackNodeId, $candidateIds, true)) {
            throw ValidationException::withMessages(['send_back_node_id' => [CaseOperationErrors::SEND_BACK_NODE]]);
        }
    }
}
