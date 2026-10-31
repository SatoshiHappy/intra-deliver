<?php

namespace Tests\Support;

use App\Models\Records\Record;
use App\Models\Records\RecordModule;

/**
 * テストで業務モジュールのレコードを参照制限ありにする(docs の 設計/業務データの入れ物/認可とメニュー/行の権限.md 2.4、ADR 0026)。
 * 業務モジュールの参照制限の既定を「制限する」にし(この後に登録・factory で作るレコードのチェックが付く)、今ある親の無いレコードのチェックも付ける
 * (参照者は空。作成者・関係者と全件の参照の人だけが見る)。業務処理を通さない(テストの前提を作るだけ)。
 */
final class RowAccessRestriction
{
    /**
     * @param  list<int>  $moduleIds  親の無い業務モジュールの id
     */
    public static function restrict(array $moduleIds): void
    {
        RecordModule::query()->whereKey($moduleIds)->update(['row_access_restricted_by_default' => true]);
        Record::query()->whereIn('record_module_id', $moduleIds)->whereNull('parent_record_id')
            ->update(['row_access_restricted' => true]);
    }

    /**
     * {@see self::restrict()} を業務モジュールのコードで。
     */
    public static function restrictCode(string $moduleCode): void
    {
        self::restrict([RecordModule::query()->where('code', $moduleCode)->sole()->id]);
    }
}
