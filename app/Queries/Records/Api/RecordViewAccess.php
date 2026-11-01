<?php

namespace App\Queries\Records\Api;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Records\RecordAccessGrantCatalog;
use App\Queries\Records\RecordModuleGate;
use App\Queries\Records\RecordRowAccess;
use DateTimeInterface;

/**
 * レコードの閲覧の判定(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節)。
 */
class RecordViewAccess
{
    public function __construct(
        private RecordModuleGate $gate,
        private RecordRowAccess $rows,
        private RecordAccessGrantCatalog $grants,
    ) {}

    /**
     * 申請の経路を含めない判定(docs の 設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節の「使わない所」で使う)。
     */
    public function canViewInModule(
        Requester $requester,
        string $moduleCode,
        int $recordId,
        DateTimeInterface $date,
    ): bool {
        $module = $this->moduleOf($moduleCode);

        return $module !== null && $this->allowsInModule($requester, $module, $recordId, $date);
    }

    /**
     * {@see self::canViewInModule()} に申請の経路を足した判定(設計/業務データの入れ物/レコードの共通機能/認可と初期データ.md の 1.1 節)。
     */
    public function canView(Requester $requester, string $moduleCode, int $recordId, DateTimeInterface $date): bool
    {
        $module = $this->moduleOf($moduleCode);

        if ($module === null) {
            return false;
        }

        return $this->allowsInModule($requester, $module, $recordId, $date)
            || (Record::query()->whereKey($recordId)->where('record_module_id', $module->id)->exists()
                && isset($this->grants->grantedViews($requester, [$recordId], $date)[$recordId]));
    }

    private function allowsInModule(
        Requester $requester,
        RecordModule $module,
        int $recordId,
        DateTimeInterface $date,
    ): bool {
        return $this->gate->allows($requester, $module, RecordModuleAction::Read, $date)
            && $this->rows->allows($requester, $module, $recordId, $date);
    }

    private function moduleOf(string $moduleCode): ?RecordModule
    {
        return RecordModule::query()->where('code', $moduleCode)->whereNull('parent_record_module_id')->first();
    }
}
