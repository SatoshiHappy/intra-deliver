<?php

namespace App\Queries\Records\Api;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordRowAccess;
use DateTimeInterface;

/**
 * レコードの閲覧の判定(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節): URL の業務モジュールの read が
 * 許され、レコードがその業務モジュールの親の無いレコードで、行の判定({@see RecordRowAccess})を通るか。業務モジュールやレコードが
 * 無ければ偽(例外にしない)。申請の経路(認可と初期データ.md の 1.2 節の RecordAccessGrants)を足すときはここに足す
 * (閲覧のルートの入口は、この判定だけで通すかを決める)。
 */
class RecordViewAccess
{
    public function __construct(
        private RecordModuleGate $gate,
        private RecordRowAccess $rows,
    ) {}

    public function canView(Requester $requester, string $moduleCode, int $recordId, DateTimeInterface $date): bool
    {
        $module = RecordModule::query()->where('code', $moduleCode)->whereNull('parent_record_module_id')->first();

        return $module !== null
            && $this->gate->allows($requester, $module, RecordModuleAction::Read, $date)
            && $this->rows->allows($requester, $module, $recordId, $date);
    }
}
