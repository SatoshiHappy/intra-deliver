<?php

namespace App\Actions\Workflow;

use App\Models\Workflow\WorkflowFlow;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * フローの機能設定の保存(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md の 5 節と同じ形。鍵 = データモデル/直列化と検査.md の 4.2 節)。
 * 今は添付ファイルを使用するかどうか。申請中の案件は申請した時の値のまま進む(案件が写しを持つ = 設計/ワークフロー/申請の周辺/データモデル.md の 2.1 節)。
 */
class UpdateFlowSettings
{
    /**
     * @throws ModelNotFoundException<WorkflowFlow> フローが無いとき
     */
    public function execute(string $flowCode, bool $isAttachmentEnabled): void
    {
        DB::transaction(function () use ($flowCode, $isAttachmentEnabled) {
            $flow = WorkflowFlow::query()->where('code', $flowCode)->lock('for no key update')->firstOrFail();
            $flow->update(['is_attachment_enabled' => $isAttachmentEnabled]);
        });
    }
}
