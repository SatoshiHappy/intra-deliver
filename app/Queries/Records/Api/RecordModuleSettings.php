<?php

namespace App\Queries\Records\Api;

use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 業務モジュールの設定の値を、ほかのモジュール(ワークフローの添付の可否)が読む口(docs の
 * 設計/ワークフロー/申請の周辺/添付/README.md の 1.1 節、使用の列 = 設計/業務データの入れ物/レコードの共通機能/添付ファイル/レコードの添付.md の 2 節)。
 * 判定しない(値を返すだけで、可否は呼び手が決める)。鍵を取らない(書き込みの口が自分の鍵の下でもう一度確かめる)。
 */
class RecordModuleSettings
{
    /**
     * 「添付ファイルを使用」の今の値。
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無い・明細のモジュールのとき(呼び手の誤り)
     */
    public function isAttachmentEnabled(int $recordModuleId): bool
    {
        return RecordModule::query()->whereNull('parent_record_module_id')->findOrFail($recordModuleId)
            ->is_attachment_enabled;
    }

    /**
     * 「添付ファイルを使用」の今の値をまとめて読む(一覧の行ごとに読み直さないため)。
     *
     * @param  list<int>  $recordModuleIds
     * @return array<int, bool> 業務モジュールの id → 値
     *
     * @throws ModelNotFoundException<RecordModule> どれかが無い・明細のモジュールのとき(呼び手の誤り)
     */
    public function attachmentEnabledByIds(array $recordModuleIds): array
    {
        $ids = array_values(array_unique($recordModuleIds));

        if ($ids === []) {
            return [];
        }

        $values = RecordModule::query()->whereNull('parent_record_module_id')->whereKey($ids)
            ->pluck('is_attachment_enabled', 'id')->map(fn (mixed $value) => $value === true)->all();
        $missing = array_values(array_diff($ids, array_keys($values)));

        if ($missing !== []) {
            throw (new ModelNotFoundException)->setModel(RecordModule::class, $missing);
        }

        return $values;
    }
}
