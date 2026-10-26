<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\Api\RecordFormQuery;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 業務モジュールの判定の口(docs の 設計/業務データの入れ物/認可とメニュー/README.md 2 節): URL の業務モジュールを引き、そのリソースで操作のアクションを判定する。
 * 読み取りと業務処理がここを通る。順は (1) 業務モジュールが無い・明細のモジュールなら 404、(2) 判定(403)。レコードの有無と行の判定はその後に呼び手が見る。
 */
class RecordModuleGate
{
    public function __construct(
        private RecordModuleAuthorization $authorization,
        private RecordRowAccess $rows,
    ) {}

    /**
     * 親の無い業務モジュール(明細のモジュールは単独で扱わない = 項目と規則/README.md 6 節)。
     *
     * @throws ModelNotFoundException<RecordModule> 無い・明細のモジュールのとき
     */
    public function topLevelModule(string $code): RecordModule
    {
        return RecordModule::query()->where('code', $code)->whereNull('parent_record_module_id')->firstOrFail();
    }

    /**
     * 判定を求める者が d に、業務モジュールのアクションを許されるか(リソースが無ければ禁止)。
     */
    public function allows(Requester $requester, RecordModule $module, RecordModuleAction $action, DateTimeInterface $date): bool
    {
        return $this->authorization->allows($requester, $module, $action, $date);
    }

    /**
     * 業務モジュールと行の判定({@see RecordRowAccess})を、入力の検証より前に確かめる(FormRequest の authorize から呼ぶ)。
     * 404・403 を検証の 422 より先に返すため(認可とメニュー/README.md 2.2・行の権限.md 2.4)。業務処理・読み取りもあとで同じ判定をする。
     *
     * @throws ModelNotFoundException<RecordModule|Record> 業務モジュール・レコードが無い・行の判定を通らないとき
     * @throws AuthorizationException 許されないとき
     */
    public function authorizeTarget(Requester $requester, string $moduleCode, RecordModuleAction $action, DateTimeInterface $date, ?int $recordId = null): void
    {
        $module = $this->topLevelModule($moduleCode);
        $this->authorize($requester, $module, $action, $date);

        if ($recordId !== null) {
            $records = Record::query()->where('record_module_id', $module->id);
            $this->rows->constrain($records, $requester, $module, $date)->findOrFail($recordId, ['id']);
        }
    }

    /**
     * @throws AuthorizationException 許されないとき(403)
     */
    public function authorize(Requester $requester, RecordModule $module, RecordModuleAction $action, DateTimeInterface $date): void
    {
        if (! $this->allows($requester, $module, $action, $date)) {
            throw new AuthorizationException(RecordFormQuery::NOT_ALLOWED_MESSAGE);
        }
    }
}
