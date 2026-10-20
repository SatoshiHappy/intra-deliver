<?php

namespace Tests\Support;

use App\Enums\Records\RecordModuleAction;
use App\Models\Authz\AuthzPolicy;
use App\Models\Authz\AuthzResourceGroup;
use App\Models\Records\Record;
use App\Models\Records\RecordModule;

/**
 * 業務データの入れ物の初期データ(RecordsSeeder)を使うテストの手助け: 項目の値でレコードを引く、業務モジュールのアクションの許可を消す
 * (初期ポリシーを置き換える = docs の 設計/業務データの入れ物/認可とメニュー/メニューと初期データ.md 8 節)。
 */
trait UsesSeededRecords
{
    /**
     * 初期データの業務モジュールのレコードのうち、項目の値が value のもの。
     */
    protected function recordNamed(string $moduleCode, string $fieldCode, string $value): Record
    {
        $module = RecordModule::query()->with('recordFields')->where('code', $moduleCode)->sole();
        $field = $module->recordFields->firstWhere('code', $fieldCode);

        return Record::query()->where('record_module_id', $module->id)->get()
            ->firstOrFail(fn (Record $record) => ($record->field_values[$field?->id] ?? null) === $value);
    }

    /**
     * 業務モジュールのアクションの許可を消す(ゲストと認証済みの全員の初期ポリシー)。
     */
    protected function deny(string $moduleCode, RecordModuleAction $action): void
    {
        $groupId = AuthzResourceGroup::query()->where('code', RecordModuleAction::resourceGroupCode($moduleCode))->sole()->id;
        AuthzPolicy::query()->where('authz_resource_group_id', $groupId)->where('action', $action->value)->delete();
    }
}
