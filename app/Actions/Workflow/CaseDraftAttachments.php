<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\RecordUploadInput;
use App\Actions\Upload\Api\DeleteUploads;
use App\Actions\Upload\Api\StoreUploads;
use App\Models\Workflow\WorkflowCase;
use App\Models\Workflow\WorkflowCaseDraftAttachment;
use App\Queries\Upload\Api\UploadLimits;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * 一時保存の添付の行の書き込み(足す・外す・申請で移す。docs の 設計/ワークフロー/申請の周辺/添付/書く順と検査.md の 6 節・7 節)。
 * 呼び手が一時保存の案件の行の鍵を取った同じトランザクションの中で呼ぶ(台帳の鍵は StoreUploads の中で最後に取る)。
 * 行を書くのは StoreUploads が返した id だけなので、残る行の実体はいつも持ち主の無い実体になり、外すときは確かめずに実体を消す(7 節の不変条件)。
 */
class CaseDraftAttachments
{
    public function __construct(
        private StoreUploads $storeUploads,
        private DeleteUploads $deleteUploads,
        private UploadLimits $limits,
    ) {}

    /**
     * ファイルを上げて、返った id の順に行を書く(名前 = StoreUploads が確かめた名前と同じ規則で作った値)。無ければ何もしない。
     *
     * @param  int  $uploaderUserId  上げた人(ログインしている利用者。代理のときは代理先)
     * @param  list<UploadedFile>  $files
     *
     * @throws ValidationException 件数・大きさ・名前・形式・合計の誤り(attachments・attachments.<添字>)
     * @throws RuntimeException 実体を書けなかったとき
     */
    public function add(WorkflowCase $draft, int $uploaderUserId, array $files): void
    {
        if ($files === []) {
            return;
        }

        $uploadIds = $this->storeUploads->execute($uploaderUserId, $files);

        foreach ($uploadIds as $index => $uploadId) {
            WorkflowCaseDraftAttachment::query()->create([
                'workflow_case_id' => $draft->id,
                'upload_id' => $uploadId,
                'name' => $this->limits->fileName($files[$index]->getClientOriginalName()),
            ]);
        }
    }

    /**
     * 外す指定の行を消し、その実体を消す(実体の削除はコミットの後)。
     *
     * @param  Collection<int, WorkflowCaseDraftAttachment>  $rows
     */
    public function remove(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $this->forget($rows);
        $this->discardUploads(array_values($rows->pluck('upload_id')->all()));
    }

    /**
     * 申請で移す上げ済みの実体(CreateRecord の attachUploads に渡す。並びは行の並び)。
     *
     * @param  Collection<int, WorkflowCaseDraftAttachment>  $rows
     * @return list<RecordUploadInput>
     */
    public function uploadInputs(Collection $rows): array
    {
        $inputs = $rows->map(
            fn (WorkflowCaseDraftAttachment $row) => new RecordUploadInput($row->upload_id, $row->name),
        );

        return array_values($inputs->all());
    }

    /**
     * 行だけを消す(申請で移した行。実体は record_attachments が指すので残す)。
     *
     * @param  Collection<int, WorkflowCaseDraftAttachment>  $rows
     */
    public function forget(Collection $rows): void
    {
        if ($rows->isNotEmpty()) {
            WorkflowCaseDraftAttachment::query()->whereKey($rows->modelKeys())->delete();
        }
    }

    /**
     * 一時保存の削除の前に集める実体の id(案件の行を消すと行は連鎖で消える)。
     *
     * @return list<int>
     */
    public function uploadIdsOf(WorkflowCase $draft): array
    {
        return array_values(WorkflowCaseDraftAttachment::query()->where('workflow_case_id', $draft->id)
            ->pluck('upload_id')->all());
    }

    /**
     * 実体を消す(指す行を消した後に呼ぶ。一時保存の削除は案件の行を消した後。実体の削除はコミットの後)。
     *
     * @param  list<int>  $uploadIds
     */
    public function discardUploads(array $uploadIds): void
    {
        if ($uploadIds !== []) {
            $this->deleteUploads->execute($uploadIds);
        }
    }
}
