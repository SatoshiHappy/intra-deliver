<?php

namespace App\Enums\Workflow;

/**
 * 一括承認・一括確認の案件ごとの結果(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節。値は画面の bulkResult の result =
 * 設計/ワークフロー/案件の運用/画面/README.md)。DB の列の値ではない。
 */
enum WorkflowBulkOutcome: string
{
    /** 承認済み */
    case Approved = 'approved';

    /** 確認済み */
    case Confirmed = 'confirmed';

    /** 処理済み(ほかの人が処理した・確認が閉じたなど、自分の対応は要らない) */
    case Processed = 'processed';

    /** 未処理 */
    case Pending = 'pending';
}
