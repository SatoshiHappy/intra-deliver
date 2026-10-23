<?php

namespace App\Enums\Workflow;

/**
 * 一括承認の 1 件を処理した結果(docs の 設計/ワークフロー/案件の運用/案件の操作/操作.md の 10.7 節の「処理」)。止まった理由が画面の古さか
 * (改訂番号か組の違い)は、結果の分け方の「もう一度承認してください。」の枝が読む。DB の列の値ではない。
 */
enum WorkflowBulkAttempt
{
    /** 承認した */
    case Approved;

    /** 画面が古い(改訂番号か組の違い、選べる組が無い)ので承認せずに止めた */
    case StoppedStale;

    /** 閲覧できない・無い案件か、単独の承認が拒んだので止めた */
    case StoppedRefused;
}
