<?php

namespace App\Enums\Workflow;

/**
 * ワークフローが案件の操作で送る通知の種類(docs の 設計/ワークフロー/案件の運用/通知.md の 3 節。値 = 表 notices の type)。
 * 値ごとの文の雛形は画面の resources/js/lib/Notice/noticeText.ts に置く(値を足すときは雛形の case も足す)。
 */
enum WorkflowNoticeType: string
{
    /** 処理依頼 */
    case ProcessRequest = 'workflow.process_request';

    /** 担当変更(振替) */
    case Transfer = 'workflow.transfer';

    /** 処理結果 */
    case Result = 'workflow.result';

    /** 確認依頼 */
    case ConfirmRequest = 'workflow.confirm_request';

    /** 連絡 */
    case Contact = 'workflow.contact';

    /**
     * 文の値に操作のコメントを持つ種類か(担当変更 = 通知.md の 3 節の表)。
     */
    public function carriesComment(): bool
    {
        return $this === self::Transfer;
    }
}
