<?php

namespace App\Http\Requests\Workflow;

use App\Actions\Workflow\CaseOperationErrors;
use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 案件操作(管理のルート workflow.admin.cases.*。処理担当者の変更・再展開・ステップの移動・指定の変更・申請の削除)の入力
 * { revision, node_id?, comment?, user_ids?, direction?, send_back_node_id? }(docs の 設計/ワークフロー/案件の運用/案件の操作/案件操作.md の
 * 15.3〜15.7 節、画面/README.md の 1.1 節)。authorize が 404・403 を封筒の検証より先に確かめる(15.1 節。判定 = {@see CaseEntryGate})。
 * 入口が見るのは形だけで、人・移動の種類・戻り先・コメントの中身は業務処理が 422 にする(形の誤りの文言は、業務処理の文言がある欄はそれを使う)。
 */
class CaseOperationRequest extends FormRequest
{
    use ReadsRevision;

    /** 申請の削除のルート(改訂番号だけを受ける) */
    private const DESTROY_ROUTE = 'workflow.admin.cases.destroy';

    /** 人の並び user_ids を受けるルート */
    private const USERS_ROUTES = ['workflow.admin.cases.retarget', 'workflow.admin.cases.assign'];

    /** ステップの移動のルート(移動の種類と戻り先を受ける) */
    private const MOVE_ROUTE = 'workflow.admin.cases.move';

    /** 人の並びの形の誤りの文言 */
    private const USERS_SHAPE = '処理担当者の指定が正しくありません';

    public function authorize(CaseEntryGate $gate): bool
    {
        $gate->authorizeCaseOperation(AccessContext::of($this), $this->caseId());

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        $node = ['node_id' => ['required', 'integer'], 'comment' => ['nullable', 'string']];
        $users = ['user_ids' => ['nullable', 'array'], 'user_ids.*' => ['integer']];
        $move = ['direction' => ['nullable', 'string'], 'send_back_node_id' => ['nullable', 'integer']];

        return [
            ...$this->revisionRules(),
            ...($this->routeIs(self::DESTROY_ROUTE) ? [] : $node),
            ...($this->routeIs(...self::USERS_ROUTES) ? $users : []),
            ...($this->routeIs(self::MOVE_ROUTE) ? $move : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->revisionMessages(),
            'node_id.required' => '対象のステップを選択してください',
            'node_id.integer' => '対象のステップの指定が正しくありません',
            'comment.string' => 'コメントの形が正しくありません',
            'user_ids.array' => self::USERS_SHAPE,
            'user_ids.*.integer' => self::USERS_SHAPE,
            'direction.string' => CaseOperationErrors::MOVE_DIRECTION,
            'send_back_node_id.integer' => CaseOperationErrors::MOVE_BACK_NODE,
        ];
    }

    /**
     * 操作するノード(削除のほかのルート。規則が required・integer を確かめた後に呼ぶ)。
     */
    public function nodeId(): int
    {
        return $this->integer('node_id');
    }

    public function comment(): ?string
    {
        $comment = $this->input('comment');

        return is_string($comment) ? $comment : null;
    }

    /**
     * 人の並び(処理担当者の変更・指定の変更。無ければ空 = 業務処理が人数の規則で 422 user_ids にするか、最小 0 の指定を 0 人にする)。
     *
     * @return list<int>
     */
    public function userIds(): array
    {
        return array_values(array_map('intval', (array) $this->input('user_ids', [])));
    }

    /**
     * 移動の種類(無ければ null = 業務処理が 422 direction にする)。
     */
    public function direction(): ?string
    {
        $direction = $this->input('direction');

        return is_string($direction) ? $direction : null;
    }

    /**
     * 戻り先(戻るだけ。無ければ null = 戻るなら業務処理が 422 send_back_node_id にする)。
     */
    public function sendBackNodeId(): ?int
    {
        return $this->input('send_back_node_id') === null ? null : $this->integer('send_back_node_id');
    }
}
