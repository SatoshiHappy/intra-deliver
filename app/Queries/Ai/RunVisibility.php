<?php

namespace App\Queries\Ai;

use App\Models\Ai\AiRun;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;

/**
 * AI の実行の閲覧の判定(正本 = docs の 設計/AI/公開の口.md 3 節の閲覧の規則)。
 * 依頼者のほかは、対象のレコードの閲覧の判定({@see RecordViewAccess})に従う(対象が明細の行なら親のレコード = 設計/AI/README.md の
 * 実行の記録の「対象のレコード」)。対象のレコードが消えた・無い実行は依頼者だけが見る。
 */
class RunVisibility
{
    public function __construct(private RecordViewAccess $access, private RecordDisplayQuery $records) {}

    /**
     * @param  DateTimeInterface  $date  基準日(業務モジュールの read と行の判定)
     */
    public function canView(Requester $requester, AiRun $run, DateTimeInterface $date): bool
    {
        if ($requester->userId !== null && $requester->userId === $run->requester_user_id) {
            return true;
        }

        $recordId = $run->record_id === null ? null : $this->records->topLevelRecordIdOf($run->record_id);

        if ($recordId === null || $run->record_module_code === null) {
            return false;
        }

        return $this->access->canView($requester, $run->record_module_code, $recordId, $date);
    }
}
