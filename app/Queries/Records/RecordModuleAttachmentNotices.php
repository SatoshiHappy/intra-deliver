<?php

namespace App\Queries\Records;

use App\Models\Records\RecordModule;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * 業務モジュールの編集の画面の、添付の許可を外す前の確かめの文(docs の 設計/業務データの入れ物/レコードの共通機能/業務モジュールの管理.md の 2 節)。
 * 呼び手は管理のルートの認可を済ませてから呼ぶ。
 */
class RecordModuleAttachmentNotices
{
    public function __construct(private RecordHoldNoticeCatalog $notices) {}

    /**
     * 外せない(明細のモジュール)・外しても変わらない(今も許可しない)業務モジュールには確かめが要らないので読まない。
     *
     * @return list<string>
     *
     * @throws ModelNotFoundException<RecordModule> 業務モジュールが無いとき
     */
    public function of(string $moduleCode): array
    {
        $module = RecordModule::query()->where('code', $moduleCode)->firstOrFail();

        return $module->parent_record_module_id === null && $module->is_attachment_enabled
            ? $this->notices->attachmentDisableNotices($module->id)
            : [];
    }
}
