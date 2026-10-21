<?php

namespace App\Actions\Workflow;

use App\Enums\Workflow\WorkflowOperation;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Models\Workflow\WorkflowCaseNode;
use App\Models\Workflow\WorkflowFlow;
use App\Queries\Workflow\CaseAttachability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * 添付の入力の規則(判定の順の 7 = 入力の規則に足すもの。docs の 設計/ワークフロー/申請の周辺/添付/README.md の 3.2 節、
 * 文言 = 3.3 節の表)。可否は {@see CaseAttachability} の 1 か所で求める。一時保存の添付の行は、呼び手が案件の行の鍵を持ったまま読む。
 */
class CaseAttachmentRules
{
    /** 受けない操作・可否が偽で添付を送った(422 attachments) */
    public const NOT_ATTACHABLE = 'この操作では添付ファイルを追加できません';

    /** 外す id がその一時保存の行でない・上げた人が実行者でない(422 remove_attachment_ids。無い行と同じ文にして、ほかの一時保存を漏らさない) */
    public const REMOVAL_NOT_FOUND = '削除する添付ファイルが見つかりません';

    /** 一時保存からの申請で、可否が偽のまま一時保存の添付が残る(422 attachments) */
    public const DRAFT_NOT_ATTACHABLE = 'この申請では添付ファイルを使用できません。一時保存の添付ファイルを削除してください。';

    public function __construct(private CaseAttachability $attachability) {}

    /**
     * 送った添付が 1 つ以上で、可否が偽なら拒む(可否を読むのは送ったときだけ)。
     *
     * @param  list<UploadedFile>  $files
     * @param  WorkflowCase|null  $case  申請の後の操作の案件({@see CaseAttachability::canAttach()} と同じ)
     *
     * @throws ValidationException 可否が偽のとき(attachments)
     */
    public function rejectFiles(
        WorkflowOperation $operation,
        WorkflowFlow $flow,
        array $files,
        ?WorkflowCase $case = null,
        ?WorkflowCaseNode $node = null,
    ): void {
        if ($files !== [] && ! $this->attachability->canAttach($operation, $flow, $case, $node)) {
            throw ValidationException::withMessages(['attachments' => [self::NOT_ATTACHABLE]]);
        }
    }

    /**
     * 外す指定の行(重複は 1 つにまとめる)。外せるのは、その一時保存の行のうち実行者が上げたものだけ(2 節の「外せる人」)。
     *
     * @param  list<int>  $ids
     * @param  int  $uploaderUserId  実行者(ログインしている利用者。代理のときは代理先)
     * @return Collection<int, WorkflowCaseDraftAttachment>
     *
     * @throws ValidationException 1 つでも当たらないとき(remove_attachment_ids)
     */
    public function removals(WorkflowCase $draft, array $ids, int $uploaderUserId): Collection
    {
        $ids = array_values(array_unique($ids));

        if ($ids === []) {
            return new Collection;
        }

        $rows = $this->rowsOf($draft)->whereKey($ids)
            ->whereHas('upload', fn (Builder $query) => $query->where('user_id', $uploaderUserId))
            ->get();

        if ($rows->count() !== count($ids)) {
            throw ValidationException::withMessages(['remove_attachment_ids' => [self::REMOVAL_NOT_FOUND]]);
        }

        return $rows;
    }

    /**
     * 一時保存からの申請で、外した後に残る行(付けた順)。残るのに可否が偽なら拒む(黙って外さず、黙って移さない = 1.1 節)。
     *
     * @param  Collection<int, WorkflowCaseDraftAttachment>  $removals
     * @return Collection<int, WorkflowCaseDraftAttachment>
     *
     * @throws ValidationException 残る行があり可否が偽のとき(attachments)
     */
    public function remainingAfter(WorkflowFlow $flow, WorkflowCase $draft, Collection $removals): Collection
    {
        $remaining = $this->rowsOf($draft)->whereKeyNot($removals->modelKeys())->orderBy('id')->get();

        if ($remaining->isNotEmpty() && ! $this->attachability->canAttach(WorkflowOperation::Apply, $flow)) {
            throw ValidationException::withMessages(['attachments' => [self::DRAFT_NOT_ATTACHABLE]]);
        }

        return $remaining;
    }

    /**
     * @return Builder<WorkflowCaseDraftAttachment>
     */
    private function rowsOf(WorkflowCase $draft): Builder
    {
        return WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft->id);
    }
}
