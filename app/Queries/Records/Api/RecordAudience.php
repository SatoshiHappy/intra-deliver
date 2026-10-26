<?php

namespace App\Queries\Records\Api;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;
use App\Queries\Authz\Api\Requester;
use App\Queries\Master\Api\EffectiveUsers;
use App\Queries\Records\RecordRowAccess;
use DateTimeInterface;

/**
 * 業務モジュールの側が関係者に送る通知の宛先(docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md の 2.4 節の「通知」)。
 * 主体の判定をしない(呼び手は業務モジュールの処理)。候補は {@see RecordRowAccess::relatedUserIds()} で、
 * 閲覧は {@see RecordViewAccess} に任せる(定義を業務モジュールの側に写さないため)。
 */
class RecordAudience
{
    public function __construct(
        private RecordViewAccess $access,
        private RecordRowAccess $rows,
        private EffectiveUsers $users,
    ) {}

    /**
     * 関係者のうち、d に有効で閲覧できるユーザー。閲覧は接続元なし・選んだ組織なしで判定する
     * (送る時に宛先を決め、受け取る人の接続元と選んだ組織を知らないため = 2.4 節の「所属」と同じ理由)。
     *
     * @return list<int> ユーザーの id(昇順・重ねない)。レコードが無い・明細の行なら空
     */
    public function relatedViewers(int $recordId, DateTimeInterface $date): array
    {
        $record = Record::query()->whereKey($recordId)->whereNull('parent_record_id')->first();
        $module = $record === null ? null : RecordModule::query()
            ->whereKey($record->record_module_id)
            ->whereNull('parent_record_module_id')
            ->first();

        if ($module === null) {
            return [];
        }

        // 有効でないユーザーは判定でゲストとして扱われ、ゲストに read があると閲覧できてしまうので先に除く。
        $candidates = $this->users->idsOn($this->rows->relatedUserIds($module, $recordId, $date), $date);

        return array_values(array_filter(
            $candidates,
            fn (int $userId) => $this->access->canView(new Requester($userId, null), $module->code, $recordId, $date),
        ));
    }
}
