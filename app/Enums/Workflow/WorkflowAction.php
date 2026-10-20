<?php

namespace App\Enums\Workflow;

/**
 * 履歴の種別(docs の 設計/ワークフロー/データモデル/README.md 1 節。表 workflow_case_histories の action)。値は DB の検査制約と同じ一覧。
 * 申請・再申請の行だけが値の写しを持ち、差戻しの行だけが差戻し先を持つ(データモデル/表.md 2.8)。案件の運用で足した種別と、
 * 差戻し先を戻るにも使う規則は 設計/ワークフロー/案件の運用/データモデル/表.md の 3.7 節。
 */
enum WorkflowAction: string
{
    /** 申請 */
    case Apply = 'apply';

    /** 再申請 */
    case Reapply = 'reapply';

    /** 承認 */
    case Approve = 'approve';

    /** 承認終了 */
    case ApproveEarly = 'approve_early';

    /** 否認 */
    case Reject = 'reject';

    /** 差戻し */
    case SendBack = 'send_back';

    /** 引戻し */
    case PullBack = 'pull_back';

    /** 取止め */
    case Withdraw = 'withdraw';

    /** 保留 */
    case Hold = 'hold';

    /** 保留解除 */
    case Release = 'release';

    /** 振替(画面の語は担当変更) */
    case Transfer = 'transfer';

    /** 処理対象者の変更(案件操作) */
    case Retarget = 'retarget';

    /** 再展開(案件操作) */
    case Reexpand = 'reexpand';

    /** 戻る(案件操作のステップの移動。差戻し先の列に戻り先を持つ) */
    case MoveBack = 'move_back';

    /** 進む(案件操作のステップの移動) */
    case MoveForward = 'move_forward';

    /** 終了(案件操作のステップの移動) */
    case MoveFinish = 'move_finish';

    /** 指定の変更(案件操作) */
    case Reassign = 'reassign';

    /**
     * 引戻せる種別(ルートの深さ/案件の操作/操作.md 10.5 の 1。否認は合議の票のため入る。承認終了・取止めは案件を完了するので入れない。
     * 3b が足す種別は、引戻せるものだけをここに足す)。
     *
     * @return list<self>
     */
    public static function pullBackable(): array
    {
        return [self::Apply, self::Reapply, self::Approve, self::Reject, self::SendBack];
    }

    /**
     * 承認として数える種別(縦配置の合議の承認の数と、横配置の次の子へ進めるか = ルートの深さ/案件の操作/README.md 2.5。3a は承認だけ。
     * 3b が足す種別は、承認として数えるものだけをここに足す)。
     *
     * @return list<self>
     */
    public static function countsAsApproval(): array
    {
        return [self::Approve];
    }
}
