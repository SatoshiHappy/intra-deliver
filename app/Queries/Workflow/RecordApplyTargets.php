<?php

namespace App\Queries\Workflow;

use App\Models\Records\Record;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;
use App\Support\AccessContext;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 既存のデータからの申請の申請先のレコードの読み方(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節・4 節の 0):
 * 404 の段で見る業務モジュール(親の無いレコードだけ)・作成者・行の判定、持ち主の有無。入口の口・申請の画面・申請の業務処理が同じ読み方を使う。
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
     * 申請の画面の GET の 404 の段で、候補から判定を求める者を決める(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節)。
     *
     * @param  list<int>  $candidateIds
     * @return int 判定を求める者のユーザーの id
     *
     * @throws ModelNotFoundException<Record> 判定を求める者がいないとき
     */
    public function requireApplicant(
        Requester $requester,
        array $candidateIds,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
    ): int {
        return $this->creatorAmong($requester, $candidateIds, $moduleCode, $recordId, $date)
            ?? throw (new ModelNotFoundException)->setModel(Record::class, [$recordId]);
    }

    /**
     * {@see self::requireApplicant()} と同じ判定で、判定を求める者がいなければ null(参照項目の候補の入口の「代理元としてフォームを開ける」が、
     * 開けないときに自分で判定するため = docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 1 節)。
     *
     * @param  list<int>  $candidateIds
     */
    public function creatorAmong(
        Requester $requester,
        array $candidateIds,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
    ): ?int {
        return $this->moduleCodeOf($recordId) === $moduleCode
            ? $this->viewingCreator($requester, $candidateIds, $moduleCode, $recordId, $date)
            : null;
    }

    /**
     * 申請の業務処理の 404 の段。更新の口は持ち主の鍵を渡すと行の判定をしないので、申請の側で済ませる
     * (docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 4 節の 0)。
     *
     * @param  Requester  $applicant  申請者(代理のときは代理元)
     *
     * @throws ModelNotFoundException<Record> 404 の段に当たるとき
     */
    public function requireRecord(
        Requester $applicant,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
    ): void {
        $candidateIds = $applicant->userId === null ? [] : [$applicant->userId];
        $this->requireApplicant($applicant, $candidateIds, $moduleCode, $recordId, $date);
    }

    /**
     * レコードの作成者(無ければ null)。業務データの入れ物の主体の判定をしない表示の口で読むので、渡す人はそのレコードを閲覧できる人に限る
     * (呼び手が閲覧の判定を済ませる = docs の 設計/ワークフロー/業務データとの接続/公開の口.md の 7 節)。
     *
     * @param  Requester  $viewer  レコードを閲覧できる人
     */
    public function creatorOf(Requester $viewer, int $recordId, DateTimeInterface $date): ?int
    {
        $createdBy = $this->display->show($viewer, $recordId, $date, AccessContext::LOCALE_CODE)['record']['createdBy'];

        return $createdBy['id'] ?? null;
    }

    /**
     * 持ち主({@see WorkflowRecordHolds})があるか。
     */
    public function isHeld(int $recordId): bool
    {
        return $this->holds->holdsOf([$recordId]) !== [];
    }

    /**
     * 作成者を読む表示の口は主体の判定をしないので、閲覧できる人を先に絞ってから読む(docs の 設計/ワークフロー/申請の周辺/既存のデータからの申請/README.md の 3 節)。
     *
     * @param  list<int>  $candidateIds
     */
    private function viewingCreator(
        Requester $requester,
        array $candidateIds,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
    ): ?int {
        $viewerIds = array_values(array_filter($candidateIds, fn (int $id): bool => $this->viewAccess
            ->canView(RecordApplyFlows::requesterAs($requester, $id), $moduleCode, $recordId, $date)));

        if ($viewerIds === []) {
            return null;
        }

        $creatorId = $this->creatorOf(RecordApplyFlows::requesterAs($requester, $viewerIds[0]), $recordId, $date);

        return in_array($creatorId, $viewerIds, true) ? $creatorId : null;
    }
}
