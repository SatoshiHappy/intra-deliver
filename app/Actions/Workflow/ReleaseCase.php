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
 * 保留解除(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.2 節。判定の順は同じ文書の 7 節)。書き込みは {@see CaseNodeWriter}。
 * 案件の管理者の解除は、この業務処理の外(管理の判定 = 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.1 節)。
 */
class ReleaseCase
{
    public function __construct(
        private CaseOperationGuard $guard,
        private CaseInputRules $inputRules,
        private CaseNodeWriter $writer,
        private ContactValidator $contactValidator,
        private CaseStepAttachments $attachments,
        private SendCaseNotices $notices,
    ) {}

    /**
     * @param  int  $revision  画面を開いた時の改訂番号
     * @param  int  $nodeId  操作するノード(案件のノードの id)
     * @param  int|null  $principalUserId  処理担当者(代理元。null = 自分として = 設計/ワークフロー/案件の運用/案件の操作/README.md の 4.3 節)
     * @param  CaseContact|null  $contact  連絡(規則 = {@see ContactValidator}。誤りは 422 contact.user_ids・contact.message)
     * @param  list<UploadedFile>  $attachFiles  付ける添付(設計/ワークフロー/申請の周辺/添付/README.md の 3.1 節の attachments。空 = 付けない)
     *
     * @throws ModelNotFoundException<WorkflowCase> 案件が無い・閲覧できないとき
     * @throws ValidationException 改訂番号・ノード・表(case)、コメント(comment)、処理担当者(principal_user_id)の誤り、
     *                             添付(attachments・attachments.<添字>)・添付を付けるレコードの持ち主(record)の誤り
     * @throws RuntimeException 添付の実体を書けなかったとき
     */
    public function execute(
        AccessContext $context,
        int $caseId,
        int $revision,
        int $nodeId,
        ?string $comment,
        ?int $principalUserId = null,
        ?CaseContact $contact = null,
        array $attachFiles = [],
    ): CaseOperationResult {
        return DB::transaction(function () use (
            $context, $caseId, $revision, $nodeId, $comment, $principalUserId, $contact, $attachFiles,
        ) {
            $permit = $this->guard->lockAndPermit(
                WorkflowOperation::Release,
                $context,
                $caseId,
                $revision,
                nodeId: $nodeId,
                principalUserId: $principalUserId,
            );
            $actor = CaseActor::forProcessor($permit->processorUserId(), $context);
            $comment = $this->inputRules->comment($comment);
            $contact = $this->contactValidator->validated($contact, $context);
            $node = $permit->node();
            $flow = $this->attachments->rejectFiles(WorkflowOperation::Release, $permit, $node, $attachFiles);
            $action = WorkflowAction::Release;
            $revisionId = $this->attachments->attach($permit, $actor, $context, $action, $node, $flow, $attachFiles);

            $waiting = WorkflowNodeState::Waiting;
            $result = $this->writer->write($permit, $actor, $action, $comment, $waiting, recordRevisionId: $revisionId);

            $this->notices->execute($result, $contact, $context->date);

            return $result;
        });
    }
}
