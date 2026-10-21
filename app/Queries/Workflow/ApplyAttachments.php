<?php

namespace App\Queries\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordAttachmentQuery;
use App\Queries\Records\Api\RecordViewAccess;
use App\Queries\Upload\Api\UploadQuery;
use App\Support\AccessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

/**
 * 申請の画面(Workflow/Apply)の attachment(docs の 設計/ワークフロー/申請の周辺/添付/README.md の 5 節): enabled = 申請の系統の可否
 * ({@see CaseAttachability})、drafts = 一時保存の添付の行(可否によらず入れる = 1.1 節。removable = ログインしている利用者が上げた行 = 2 節の
 * 「外せる人」)。新規の申請・再申請・既存のデータからの申請の drafts は空。attached = レコードに付いている添付(再申請と既存のデータからの申請。
 * 名前・大きさを新しい順に上限まで。attachedCount = 全部の件数)。付いている添付は、レコードの閲覧の判定({@see RecordViewAccess} = サイドパネルと同じ見る人 = 1.1 節)が
 * 偽なら出さない。
 *
 * @phpstan-import-type AttachmentLimits from WorkflowAttachmentLimits
 *
 * @phpstan-type DraftAttachment array{id: int, name: string, size: int, removable: bool}
 * @phpstan-type AttachedFile array{name: string, size: int}
 * @phpstan-type ApplyAttachment array{enabled: bool, limits: AttachmentLimits, drafts: list<DraftAttachment>,
 *     attached: list<AttachedFile>, attachedCount: int}
 */
class ApplyAttachments
{
    /** 付いている添付を読む上限(残りは件数だけ = 欄の「ほかに N 件」) */
    public const ATTACHED_LIMIT = 20;

    public function __construct(
        private CaseAttachability $attachability,
        private WorkflowAttachmentLimits $limits,
        private UploadQuery $uploads,
        private RecordAttachmentQuery $recordAttachments,
        private RecordViewAccess $access,
    ) {}

    /**
     * 新規の申請(申請の前 = フローの今の値)。
     *
     * @return ApplyAttachment
     */
    public function forNew(WorkflowFlow $flow): array
    {
        return $this->props($this->attachability->canAttach(WorkflowOperation::Apply, $flow), [], [], 0);
    }

    /**
     * 一時保存の編集(申請の前 = フローの今の値)。
     *
     * @param  int|null  $userId  ログインしている利用者(代理のときは代理先)
     * @return ApplyAttachment
     *
     * @throws LogicException 台帳の行が無い一時保存の添付の行があるとき(外部キーで起こらない)
     */
    public function forDraft(WorkflowFlow $flow, WorkflowCase $draft, ?int $userId): array
    {
        $enabled = $this->attachability->canAttach(WorkflowOperation::OverwriteDraft, $flow);

        return $this->props($enabled, $this->draftRows($draft, $userId), [], 0);
    }

    /**
     * 再申請(申請の後 = 案件に写した値)。付いている添付は案件のレコードのもの。
     *
     * @return ApplyAttachment
     *
     * @throws ModelNotFoundException レコードが無いとき(申請した案件のレコードは必ずある)
     */
    public function forReapply(
        AccessContext $context,
        WorkflowFlow $flow,
        WorkflowCase $case,
        string $moduleCode,
        int $recordId,
    ): array {
        $enabled = $this->attachability->canAttach(WorkflowOperation::Reapply, $flow, $case);

        return $this->withAttached($enabled, $context, $moduleCode, $recordId);
    }

    /**
     * 既存のデータからの申請(申請の前 = フローの今の値)。付いている添付は申請するレコードのもの(表示だけ =
     * 設計/ワークフロー/申請の周辺/添付/README.md の 2 節)。
     *
     * @param  AccessContext  $applicant  画面を引く申請者(代理のときは代理元)
     * @return ApplyAttachment
     */
    public function forRecord(AccessContext $applicant, WorkflowFlow $flow, string $moduleCode, int $recordId): array
    {
        $enabled = $this->attachability->canAttach(WorkflowOperation::Apply, $flow);

        return $this->withAttached($enabled, $applicant, $moduleCode, $recordId);
    }

    /**
     * 付いている添付を入れた欄(閲覧できないレコードなら空)。
     *
     * @return ApplyAttachment
     */
    private function withAttached(bool $enabled, AccessContext $context, string $moduleCode, int $recordId): array
    {
        $requester = Requester::fromAccessContext($context);

        if (! $this->access->canView($requester, $moduleCode, $recordId, $context->date)) {
            return $this->props($enabled, [], [], 0);
        }

        $page = $this->recordAttachments->forRecord($recordId, self::ATTACHED_LIMIT);
        $attached = array_map(fn (array $item) => ['name' => $item['name'], 'size' => $item['size']], $page['items']);
        // 続きが無いときは読んだ件数が全部(件数を別の読みにすると、間に増減があって attached より小さくなり得る)
        $count = $page['hasMore'] ? $this->recordAttachments->countFor($recordId) : count($attached);

        return $this->props($enabled, [], $attached, max($count, count($attached)));
    }

    /**
     * @param  list<DraftAttachment>  $drafts
     * @param  list<AttachedFile>  $attached
     * @return ApplyAttachment
     */
    private function props(bool $enabled, array $drafts, array $attached, int $attachedCount): array
    {
        return [
            'enabled' => $enabled,
            'limits' => $this->limits->props(),
            'drafts' => $drafts,
            'attached' => $attached,
            'attachedCount' => $attachedCount,
        ];
    }

    /**
     * 一時保存の添付の行(付けた順)。大きさは台帳の公開の口で読む。
     *
     * @return list<DraftAttachment>
     *
     * @throws LogicException 台帳の行が無いとき
     */
    private function draftRows(WorkflowCase $draft, ?int $userId): array
    {
        $rows = WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft->id)
            ->withExists(['upload as removable' => fn (Builder $query) => $query->where('user_id', $userId)])
            ->orderBy('id')->get();
        $files = $this->uploads->find(array_values($rows->pluck('upload_id')->all()));

        return array_values($rows->map(fn (WorkflowCaseDraftAttachment $row) => [
            'id' => $row->id,
            'name' => $row->name,
            'size' => ($files[$row->upload_id] ?? throw new LogicException("台帳の行 {$row->upload_id} が無い"))->size,
            'removable' => $userId !== null && $row->getAttribute('removable') === true,
        ])->all());
    }
}
