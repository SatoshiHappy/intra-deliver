<?php

namespace App\Enums\Workflow;

/**
 * ステップの移動の種類(入力 direction。docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の 15.5 節)。DB の列の値ではない。
 * 種類ごとの表の行({@see WorkflowOperation})と、書く履歴の行の種別({@see WorkflowAction})の 1 か所。
 */
enum WorkflowMoveDirection: string
{
    /** 戻る(差戻しと同じ計画) */
    case Back = 'back';

    /** 進む(承認・再申請と同じ計画) */
    case Forward = 'forward';

    /** 終了(承認終了と同じ計画) */
    case Finish = 'finish';

    public function operation(): WorkflowOperation
    {
        return match ($this) {
            self::Back => WorkflowOperation::MoveBack,
            self::Forward => WorkflowOperation::MoveForward,
            self::Finish => WorkflowOperation::MoveFinish,
        };
    }

    public function action(): WorkflowAction
    {
        return match ($this) {
            self::Back => WorkflowAction::MoveBack,
            self::Forward => WorkflowAction::MoveForward,
            self::Finish => WorkflowAction::MoveFinish,
        };
    }
}
