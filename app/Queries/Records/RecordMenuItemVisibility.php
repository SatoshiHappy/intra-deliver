<?php

namespace App\Queries\Records;

use App\Enums\Records\RecordModuleAction;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Menu\Api\MenuItemRoute;
use App\Queries\Menu\Api\MenuItemVisibility;
use DateTimeInterface;

/**
 * 業務の一覧(records.data.index)を指すメニューのアイテムのうち、開くと 404・403 になるものを隠す(docs の
 * 設計/ワークフロー/申請の周辺/メニューと導線.md 1.2)。
 */
class RecordMenuItemVisibility implements MenuItemVisibility
{
    /** 受け持つルートの名前 */
    private const ROUTE_NAME = 'records.data.index';

    public function __construct(private RecordModuleGate $gate) {}

    public function hiddenItems(Requester $requester, array $items, DateTimeInterface $date): array
    {
        $moduleCodes = $this->moduleCodesByItemId($items);
        $readableCodes = $this->readableModuleCodes(array_values(array_unique($moduleCodes)), $requester, $date);

        return array_keys(array_filter($moduleCodes, fn (string $code) => ! isset($readableCodes[$code])));
    }

    /**
     * 受け持つルートのアイテムの id → 引数 module の値(文字列でなければ空文字 = 業務モジュールが無いものとして隠す)。
     *
     * @param  array<int, MenuItemRoute>  $items
     * @return array<int, string>
     */
    private function moduleCodesByItemId(array $items): array
    {
        $moduleCodes = [];

        foreach ($items as $itemId => $route) {
            if ($route->routeName === self::ROUTE_NAME) {
                $code = $route->parameters['module'] ?? null;
                $moduleCodes[$itemId] = is_string($code) ? $code : '';
            }
        }

        return $moduleCodes;
    }

    /**
     * 渡したコードのうち、親の無い業務モジュールがあり、その read を許されるもの(コード → true)。判定は業務の画面と同じ
     * {@see RecordModuleGate::allows()} を通す。
     *
     * @param  list<string>  $moduleCodes
     * @return array<string, true>
     */
    private function readableModuleCodes(array $moduleCodes, Requester $requester, DateTimeInterface $date): array
    {
        if ($moduleCodes === []) {
            return [];
        }

        $readable = [];
        $modules = RecordModule::query()->whereIn('code', $moduleCodes)->whereNull('parent_record_module_id')->get();

        foreach ($modules as $module) {
            if ($this->gate->allows($requester, $module, RecordModuleAction::Read, $date)) {
                $readable[$module->code] = true;
            }
        }

        return $readable;
    }
}
