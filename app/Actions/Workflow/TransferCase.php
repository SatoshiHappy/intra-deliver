<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeState;
use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Queries\Workflow\CaseActor;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 振替(画面の語は担当変更。docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.3 節。判定の順は同じ文書の 7 節)。
 * 振替先の規則は {@see TransferRules}、書き込みは {@see CaseNodeWriter}。
 */
class TransferCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private TransferRules $transferRules,
        private CaseNodeWriter $writer,
        private ContactValidator $contactValidator,
        private CaseStepAttachments $attachments,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(案件のノードの id)
     * @param  int|null  $toUserId  振替先(入力 to_user_id)
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     * @param  CaseContact|null  $contact  連絡(規則 = {@see ContactValidator}。誤りは 422 contact.user_ids・contact.message)
     * @param  list<UploadedFile>  $attachFiles  付ける添付(設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節の attachments。空 = 付けない)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・ノード・表(case)、コメント(comment)、振替先(to_user_id)、処理担当者(principal_user_id)の誤り、
     *                             添付(attachments・attachments.<添字>)・添付を付けるレコードの持ち主(record)の誤り
     * @throws RuntimeException 添付の実体を書けなかったとき
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        ?int $toUserId,
        ?string $comment,
        ?int $principalUserId = null,
        ?CaseContact $contact = null,
        array $attachFiles = [],
    ): CaseOperationResult {
        return DB::transaction(function () use (
            $context, $caseId, $revision, $nodeId, $toUserId, $comment, $principalUserId, $contact, $attachFiles,
        ) {
            $permit = $this->guard->lockAndPermit(
                WorkflowOperation::Transfer,
                $context,
                $caseId,
                $revision,
                nodeId: $nodeId,
                principalUserId: $principalUserId,
            );
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $comment = $this->inputRules->comment($comment);
            $contact = $this->contactValidator->validated($contact, $context);
            $toUserId = $this->transferRules->validated($permit, $actor, $toUserId);
            $node = $permit->node();
            $flow = $this->attachments->rejectFiles(WorkflowOperation::Transfer, $permit, $node, $attachFiles);
            $action = WorkflowAction::Transfer;
            $revisionId = $this->attachments->attach($permit, $actor, $context, $action, $node, $flow, $attachFiles);

            $waiting = WorkflowNodeState::Waiting;

            $result = $this->writer->write($permit, $actor, $action, $comment, $waiting, $toUserId, $revisionId);

            $this->notices->execute($result, $contact, $context->date);

            return $result;
        });
    }
}
