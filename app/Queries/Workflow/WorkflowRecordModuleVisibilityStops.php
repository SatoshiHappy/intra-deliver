<?php

namespace App\Queries\Workflow;

use App\Models\Workflow\WorkflowContent;
use App\Queries\Records\Api\RecordModuleVisibilityStops;

/**
 * 業務モジュールの表示の止めのワークフローの実装(ADR 0031 の決定 4。口の保証 = docs の
 * 設計/ワークフロー/業務データとの接続/公開の口.md 7 節の「表示の止めを立てる側の保証」)。止める業務モジュール = どれかのコンテンツが指す業務モジュール
 * (申請書の業務モジュール)。起動(App\Providers\AppServiceProvider)がタグ {@see RecordModuleVisibilityStops::TAG} で登録する。
 * コンテンツの表を鍵なしで読む。
 */
class WorkflowRecordModuleVisibilityStops implements RecordModuleVisibilityStops
{
    /** 止めの理由の文言 */
    public const REASON = '申請書の業務モジュールは、ワークフローのアプリに表示します。';

    public function stopReason(int $recordModuleId): ?string
    {
        return WorkflowContent::query()->where('record_module_id', $recordModuleId)->exists() ? self::REASON : null;
    }
}
