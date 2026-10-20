<?php

namespace App\Http\Requests\Workflow;

use App\Queries\Workflow\CaseEntryGate;
use App\Support\AccessContext;
use Illuminate\Foundation\Http\FormRequest;

/**
 * 案件の処理(承認・承認終了・否認・差戻し・引戻し・取止め)の入力 { comment, revision, node_id?, send_back_node_id?, assignments? }(docs の 設計/ワークフロー/画面.md 6 節、
 * ルートの深さ/案件の操作/操作.md 10.2〜10.5、同時実行と検査.md 13 節)。承認・承認終了・否認・差戻し・引戻しは操作するノード、差戻しは差戻し先、承認は指定(任意)を受ける。
 * コメントの長さと使えない文字・ノードがこの案件のものか・差戻し先が候補にあるか・指定の中身は業務処理が 422 にする。
 */
class CaseActionRequest extends FormRequest
{
    use ReadsRevision;

    /** 差戻しのルートの名前(差戻し先を求める) */
    private const SEND_BACK_ROUTE = 'workflow.cases.send-back';

    /** 承認のルートの名前(指定を受ける) */
    private const APPROVE_ROUTE = 'workflow.cases.approve';

    /** 操作するノードを求めるルート */
    private const NODE_ROUTES = [self::APPROVE_ROUTE, 'workflow.cases.approve-early', 'workflow.cases.reject', self::SEND_BACK_ROUTE, 'workflow.cases.pull-back'];

    /**
     * 案件が無い・閲覧できない 404 を、封筒の検証より前に確かめる(処理は 403 を求めない = 設計/ワークフロー/案件の操作/操作.md 3.1 の 1)。
     */
    public function authorize(CaseEntryGate $gate): bool
    {
        $gate->authorizeCase(AccessContext::of($this), $this->caseId());

        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            ...$this->revisionRules(),
            'comment' => ['nullable', 'string'],
            ...($this->routeIs(...self::NODE_ROUTES) ? ['node_id' => ['required', 'integer']] : []),
            ...($this->routeIs(self::SEND_BACK_ROUTE) ? ['send_back_node_id' => ['required', 'integer']] : []),
            ...($this->routeIs(self::APPROVE_ROUTE) ? ['assignments' => ['nullable', 'array'], 'assignments.*' => ['array'], 'assignments.*.*' => ['integer']] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->revisionMessages(),
            'comment.string' => 'コメントの形が正しくありません',
            'send_back_node_id.required' => '差戻し先を選択してください',
            'send_back_node_id.integer' => '差戻し先の指定が正しくありません',
            'node_id.required' => '処理するステップを選択してください',
            'node_id.integer' => '処理するステップの指定が正しくありません',
            'assignments.array' => '指定の形が正しくありません',
            'assignments.*.array' => '指定の形が正しくありません',
            'assignments.*.*.integer' => '指定の形が正しくありません',
        ];
    }

    public function comment(): ?string
    {
        $comment = $this->input('comment');

        return is_string($comment) ? $comment : null;
    }

    /**
     * 操作するノード(承認・承認終了・否認・差戻し・引戻しのルートだけ。規則が required・integer を確かめた後に呼ぶ)。
     */
    public function nodeId(): int
    {
        return $this->integer('node_id');
    }

    /**
     * 指定(承認のルートだけ。規則が形を確かめた後に呼ぶ。数でないキーは 0 = どのノードにも当たらず業務処理が 422 assignments にする)。
     *
     * @return array<int, list<int>> 指定されるノードの id → ユーザーの id の並び
     */
    public function assignments(): array
    {
        $assignments = [];

        foreach ((array) $this->input('assignments', []) as $nodeId => $userIds) {
            $assignments[is_int($nodeId) ? $nodeId : 0] = array_values(array_map('intval', (array) $userIds));
        }

        return $assignments;
    }

    /**
     * 差戻し先(差戻しのルートだけ。規則が required・integer を確かめた後に呼ぶ)。
     */
    public function sendBackNodeId(): int
    {
        return $this->integer('send_back_node_id');
    }
}
