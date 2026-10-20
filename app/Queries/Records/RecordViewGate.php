<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use App\Queries\Records\Api\RecordViewAccess;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 閲覧の判定({@see RecordViewAccess})を使うルートの入口。通すかは canView だけで決め、拒むときに 404 と 403 を分ける
 * (読める人にだけレコードの有無を見せる = docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節)。
 */
class RecordViewGate
{
    public function __construct(
        private RecordModuleGate $gate,
        private RecordViewAccess $access,
    ) {}

    /**
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュールが無い・明細のモジュール、読めるがレコードが見えないとき
     * @throws AuthorizationException 見えず、業務モジュールの read も許されないとき
     */
    public function authorize(Requester $requester, string $moduleCode, int $recordId, DateTimeInterface $date): void
    {
        $module = $this->gate->topLevelModule($moduleCode);

        if ($this->access->canView($requester, $moduleCode, $recordId, $date)) {
            return;
        }

        if ($this->gate->allows($requester, $module, RecordModuleAction::Read, $date)) {
            throw (new ModelNotFoundException)->setModel(Record::class, [$recordId]);
        }

        throw new AuthorizationException(RecordFormQuery::NOT_ALLOWED_MESSAGE);
    }
}
