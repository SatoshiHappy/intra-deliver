<?php

namespace App\Queries\Workflow;

use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 既存のデータからの申請の申請先のレコードの読み方(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請.md の 3 節・4 節の 0):
 * 404 の段で見る業務モジュール(親の無いレコードだけ)と申請者の行の判定、持ち主の有無。入口の口・申請の画面・申請の業務処理が同じ読み方を使う。
 * 鍵を取らない(競合は申請の行の一意と外部キー、更新の口の持ち主の確かめが止める = 同じ文書の 5 節)。
 */
class RecordApplyTargets
{
    public function __construct(
        private RecordDisplayQuery $display,
        private WorkflowRecordHolds $holds,
        private RecordViewAccess $viewAccess,
    ) {}

    /**
     * 親の無いレコードの業務モジュールのコード。無いレコード・明細の行は null。
     */
    public function moduleCodeOf(int $recordId): ?string
    {
        if ($this->display->topLevelRecordIdOf($recordId) !== $recordId) {
            return null;
        }

        return $this->display->topLevelModuleCodeOf($recordId);
    }

    /**
     * 404 の段: そのフローの業務モジュールの親の無いレコードで、申請者が行の判定を通るか(通らないレコードは無いのと同じ)。
     * 更新の口は持ち主の鍵を渡すと行の判定をしないので、申請の側で申請者の行の判定を済ませる。
     *
     * @param  Requester  $applicant  申請者(代理のときは代理元)
     *
     * @throws ModelNotFoundException<Record> レコードが無い・その業務モジュールの親の無いレコードでない・行の判定を通らないとき
     */
    public function requireRecord(
        Requester $applicant,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
    ): void {
        if ($this->moduleCodeOf($recordId) !== $moduleCode
            || ! $this->viewAccess->canView($applicant, $moduleCode, $recordId, $date)) {
            throw (new ModelNotFoundException)->setModel(Record::class, [$recordId]);
        }
    }

    /**
     * 持ち主(未完了の案件)があるか。
     */
    public function isHeld(int $recordId): bool
    {
        return $this->holds->holdsOf([$recordId]) !== [];
    }
}
