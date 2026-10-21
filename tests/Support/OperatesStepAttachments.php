<?php

namespace Tests\Support;

use App\Actions\Workflow\ApproveCase;
use App\Actions\Workflow\ApproveCaseEarly;
use App\Actions\Workflow\CaseOperationResult;
use App\Actions\Workflow\HoldCase;
use App\Actions\Workflow\PullBackCase;
use App\Actions\Workflow\RejectCase;
use App\Actions\Workflow\ReleaseCase;
use App\Actions\Workflow\SendBackCase;
use App\Actions\Workflow\TransferCase;
use App\Actions\Workflow\WithdrawCase;
use App\Models\Master\User;
use App\Models\Records\RecordAttachment;
use App\Models\Records\RecordRevision;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseHistory;
use Illuminate\Http\UploadedFile;
use LogicException;

/**
 * ステップの処理の添付のテストの手助け(docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 9.1 節・9.2 節)。
 * 9 つの処理を、操作の名前・人・添付で本物の業務処理として呼ぶ。フロー = 申請 → 承認1(u0002)→ 承認2(u0003)。
 */
trait OperatesStepAttachments
{
    use OperatesCaseAttachments;

    /**
     * 処理を今の改訂番号で行う。操作するノード = その人(代理なら代理元)が処理するノード、引戻しは引戻せるノード、差戻しは申請へ、
     * 振替は u0004 へ。
     *
     * @param  list<UploadedFile>  $files
     */
    protected function step(
        string $name,
        User $user,
        WorkflowCase $case,
        array $files = [],
        ?User $principal = null,
    ): CaseOperationResult {
        $context = $this->contextOf($user);
        [$id, $revision, $principalId] = [$case->id, $this->revisionOf($case), $principal?->id];
        $processorId = $principalId ?? $user->id;
        $nodeId = $name === 'pull_back'
            ? $this->pullBackNodeId($id, $processorId)
            : $this->operatedNodeId($id, $processorId);
        [$c, $r, $n, $p] = [$context, $revision, $nodeId, $principalId];

        return match ($name) {
            'approve' => app(ApproveCase::class)->execute($c, $id, $r, $n, null, [], $p, attachFiles: $files),
            'approve_early' => app(ApproveCaseEarly::class)->execute($c, $id, $r, $n, null, $p, attachFiles: $files),
            'reject' => app(RejectCase::class)->execute($c, $id, $r, $n, null, $p, attachFiles: $files),
            'send_back' => app(SendBackCase::class)
                ->execute($c, $id, $r, $n, $this->caseNode($case, 'apply')->id, null, $p, attachFiles: $files),
            'pull_back' => app(PullBackCase::class)->execute($c, $id, $r, $n, null, $p, attachFiles: $files),
            'withdraw' => app(WithdrawCase::class)->execute($c, $id, $r, null, $p, attachFiles: $files),
            'hold' => app(HoldCase::class)->execute($c, $id, $r, $n, null, $p, attachFiles: $files),
            'release' => app(ReleaseCase::class)->execute($c, $id, $r, $n, null, $p, attachFiles: $files),
            'transfer' => app(TransferCase::class)
                ->execute($c, $id, $r, $n, $this->seededUser('u0004')->id, null, $p, attachFiles: $files),
            default => throw new LogicException("処理の名前が無い: {$name}"),
        };
    }

    /**
     * 処理ができる状態の案件(申請者 u0001)と、処理する人・操作するノードのコード。
     *
     * @return array{WorkflowCase, User, string}
     */
    protected function caseReadyFor(string $name): array
    {
        [$u0001, $u0002] = [$this->seededUser('u0001'), $this->seededUser('u0002')];
        $case = $this->applyAs($u0001);

        return match ($name) {
            'pull_back' => [$this->after(fn () => $this->step('approve', $u0002, $case), $case), $u0002, 'approval1'],
            'withdraw' => [$this->after(fn () => $this->step('send_back', $u0002, $case), $case), $u0001, 'apply'],
            'release' => [$this->after(fn () => $this->step('hold', $u0002, $case), $case), $u0002, 'approval1'],
            default => [$case, $u0002, 'approval1'],
        };
    }

    /**
     * @return array{int|null, int|null, string|null, string|null, int} 最新の版の [操作した人, 処理者, 契機の処理の種別, ステップ名, 添付の数]
     */
    protected function latestRevision(WorkflowCase $case): array
    {
        $row = RecordRevision::query()->where('record_id', $case->record_id)->orderByDesc('revision')->firstOrFail();
        $attachments = $row->snapshot['attachments'] ?? [];

        return [
            $row->user_id, $row->principal_user_id, $row->origin_operation, $row->origin_node_name,
            is_array($attachments) ? count($attachments) : 0,
        ];
    }

    protected function latestRevisionId(WorkflowCase $case): int
    {
        return RecordRevision::query()->where('record_id', $case->record_id)
            ->orderByDesc('revision')->firstOrFail()->id;
    }

    protected function revisionCount(WorkflowCase $case): int
    {
        return RecordRevision::query()->where('record_id', $case->record_id)->count();
    }

    protected function historyRevisionId(CaseOperationResult $result): ?int
    {
        return WorkflowCaseHistory::query()->findOrFail($result->historyId)->record_revision_id;
    }

    /**
     * @return list<array{int|null, int|null}> レコードの添付(付けた順)の [付けた人, 実体を上げた人]
     */
    protected function attachedBy(WorkflowCase $case): array
    {
        return array_values(RecordAttachment::query()->with('upload')
            ->where('record_id', $case->record_id)->orderBy('id')->get()
            ->map(fn (RecordAttachment $row) => [$row->user_id, $row->upload?->user_id])->all());
    }

    /**
     * @param  callable(): mixed  $operation
     */
    private function after(callable $operation, WorkflowCase $case): WorkflowCase
    {
        $operation();

        return $case;
    }
}
