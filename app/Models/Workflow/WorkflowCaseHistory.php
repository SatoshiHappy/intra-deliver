<?php

namespace App\Models\Workflow;

use App\Enums\Workflow\WorkflowAction;
use App\Models\Master\User;
use App\Models\Records\RecordRevision;
use Database\Factories\Workflow\WorkflowCaseHistoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 履歴(案件の処理の記録。案件の部品。追記だけ)。表 workflow_case_histories(docs の 設計/ワークフロー/データモデル/表.md 2.8、ルートの深さ/データモデル/案件の表.md 3.7)。
 * 処理日時は created_at、並びは id。申請・再申請の行はいつもその操作の更新履歴の版を指し(record_revision_id。docs の
 * 設計/業務データの入れ物/レコードの共通機能/更新履歴.md の 4.2 節)、ステップの処理の行は添付を付けたときだけその版を指し
 * (指すかどうかは行の操作で決まらない)、差戻しの行だけが差戻し先を持つ。
 * 引戻し以外の行は、この行の操作が変えた(この行のノード以外の)ノードの操作の前の状態 node_changes を持ち、引戻しの行だけが引戻した行を指す。
 * 実行者は代理で処理したときの代理先(本人の実行は null。設計/ワークフロー/案件の運用/データモデル/表.md の 3.7 節)。
 */
#[Fillable([
    'workflow_case_id', 'workflow_case_node_id', 'action', 'user_id', 'comment', 'send_back_workflow_case_node_id',
    'node_changes', 'reverted_workflow_case_history_id', 'executor_user_id', 'record_revision_id',
])]
class WorkflowCaseHistory extends Model
{
    /** @use HasFactory<WorkflowCaseHistoryFactory> */
    use HasFactory;

    /** コメントの最大の文字数(列 comment の varchar(4000) = マイグレーションの `string('comment', 4000)` と同じ値。業務処理の入力の検証と画面の案内はこの値を参照する) */
    public const COMMENT_MAX = 4000;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action' => WorkflowAction::class,
            'node_changes' => 'array',
        ];
    }

    /**
     * @return BelongsTo<WorkflowCase, $this>
     */
    public function workflowCase(): BelongsTo
    {
        return $this->belongsTo(WorkflowCase::class);
    }

    /**
     * 処理したノード(引戻しは、引戻した人のノード = 戻した先)。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function workflowCaseNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class);
    }

    /**
     * 処理者。
     *
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * 実行者(代理で処理したときの代理先。本人の実行は null)。
     *
     * @return BelongsTo<User, $this>
     */
    public function executor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'executor_user_id');
    }

    /**
     * 差戻し先(差戻し・戻るの行だけ。ほかは null)。
     *
     * @return BelongsTo<WorkflowCaseNode, $this>
     */
    public function sendBackWorkflowCaseNode(): BelongsTo
    {
        return $this->belongsTo(WorkflowCaseNode::class, 'send_back_workflow_case_node_id');
    }

    /**
     * その操作で書いた更新履歴の版(申請・再申請の行はいつも指す。添付を変えない処理の行は null)。
     *
     * @return BelongsTo<RecordRevision, $this>
     */
    public function recordRevision(): BelongsTo
    {
        return $this->belongsTo(RecordRevision::class);
    }

    /**
     * 引戻した行(引戻しの行だけ。ほかは null)。
     *
     * @return BelongsTo<self, $this>
     */
    public function revertedHistory(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverted_workflow_case_history_id');
    }
}
