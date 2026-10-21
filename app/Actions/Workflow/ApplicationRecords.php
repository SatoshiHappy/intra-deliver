<?php

namespace App\Actions\Workflow;

use App\Actions\Records\Api\RecordRevisionOrigin;
use App\Enums\Workflow\WorkflowAction;
use App\Enums\Workflow\WorkflowNodeKind;
use App\Models\Workflow\WorkflowFlow;
use App\Models\Workflow\WorkflowRouteNode;
use App\Queries\Records\Api\RecordDisplayQuery;
use App\Queries\Records\Api\RecordRevisionQuery;
use App\Queries\Workflow\CaseActor;
use LogicException;

/**
 * 申請・再申請・ステップの処理が業務データの入れ物の口へ渡す業務モジュールのコードと版の契機、口の後に読む版の id、案件名の既定
 * (docs の 設計/ワークフロー/案件の操作/操作.md 3.5・3.9、設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)。
 */
class ApplicationRecords
{
    public function __construct(
        private RecordDisplayQuery $display,
        private RecordRevisionQuery $revisions,
    ) {}

    /**
     * 版の契機(処理の種別の画面の語とステップ名 = 更新履歴.md の 1 節の表。ステップの処理で付ける添付を含む)。実行者は、履歴を書くのと
     * 同じ操作する人で処理者と違うとき(代理)だけ渡す(本人の実行は null = 更新履歴.md の 2 節)。
     *
     * @throws LogicException レコードを書かない種別(案件操作)のとき(呼び手の誤り)
     */
    public function origin(WorkflowAction $action, string $nodeName, CaseActor $actor): RecordRevisionOrigin
    {
        $operation = match ($action) {
            WorkflowAction::Apply => '申請',
            WorkflowAction::Reapply => '再申請',
            WorkflowAction::Approve => '承認',
            WorkflowAction::ApproveEarly => '承認終了',
            WorkflowAction::Reject => '否認',
            WorkflowAction::SendBack => '差戻し',
            WorkflowAction::PullBack => '引戻し',
            WorkflowAction::Withdraw => '取止め',
            WorkflowAction::Hold => '保留',
            WorkflowAction::Release => '保留解除',
            WorkflowAction::Transfer => '担当変更',
            default => throw new LogicException("レコードを書かない操作は版の契機を作らない: {$action->value}"),
        };

        return new RecordRevisionOrigin($operation, $nodeName, $actor->recordedExecutorUserId());
    }

    /**
     * 版のルートの申請のノードの名前(新規の申請の契機のステップ名。案件のノードを写す前に読む)。
     */
    public function applyNodeName(int $versionId): string
    {
        return WorkflowRouteNode::query()
            ->where('workflow_flow_version_id', $versionId)->where('kind', WorkflowNodeKind::Apply)->sole()->name;
    }

    /**
     * 業務データの入れ物の口が書いた版の id(口が戻った後に、同じトランザクションで読む = 更新履歴.md の 4.2 節)。
     *
     * @throws LogicException 版が無いとき(契機を渡した口はいつも版を書く)
     */
    public function revisionOf(int $recordId): int
    {
        return $this->revisions->latestId($recordId)
            ?? throw new LogicException("レコード {$recordId} に版が無い(契機を渡した口が書いていない)");
    }

    /**
     * フローのコンテンツの業務モジュールのコード(record_modules の code を 1 回の結合で読むだけ = 業務データとの接続/公開の口.md 7 節。外部キーで必ずある)。
     *
     * @throws LogicException 無いとき(外部キーの不変条件が崩れた)
     */
    public function moduleCodeOf(WorkflowFlow $flow): string
    {
        $code = WorkflowFlow::query()
            ->join('workflow_contents', 'workflow_contents.id', '=', 'workflow_flows.workflow_content_id')
            ->join('record_modules', 'record_modules.id', '=', 'workflow_contents.record_module_id')
            ->whereKey($flow->id)
            ->value('record_modules.code');

        return is_string($code) ? $code : throw new LogicException("フロー {$flow->id} のコンテンツの業務モジュールが無い");
    }

    /**
     * 案件名の既定: <フロー名> <レコードの表示の項目の値>。表示の項目が無いか値が空ならフロー名だけ。1000 文字を超える分は切る。
     * 業務データの入れ物の口の後に呼ぶ(書いた後の値から決める)。
     */
    public function defaultName(WorkflowFlow $flow, int $recordId): string
    {
        $title = $this->display->titleOf($recordId);
        $name = $title === null || $title === '' ? $flow->name : "{$flow->name} {$title}";

        return mb_substr($name, 0, CaseInputRules::NAME_MAX);
    }
}
