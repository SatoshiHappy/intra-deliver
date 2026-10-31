<?php

namespace Tests\Feature\Http\Controllers\Web\Workflow;

use App\Actions\Workflow\FlowDefinitionErrors as Errors;
use App\Http\Requests\Workflow\FlowNodeSettingsRequest;
use Tests\Support\SendsFlowDefinitionRequests;
use Tests\Support\TransactionalTestCase;

/**
 * 定義の管理の書き込みの封筒の誤り(docs の 設計/ワークフロー/ルートの深さ/定義の管理.md 6 節の判定の順と文言): 型の違う値・欠けた値を欄ごとに、
 * キー(対象者の行は <欄>.<添字>)と文言を確かめる。ステップの設定の欠けた欄と null は空として業務処理へ渡す(必須は業務処理の 422)。
 * どれも何も変わらない。システム管理者 u0004、初期データのプロジェクト計画の承認。
 */
final class AdminFlowDefinitionInputTest extends TransactionalTestCase
{
    use SendsFlowDefinitionRequests;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs($this->seededAccount(self::ADMIN));
    }

    public function test_the_start_date_of_a_new_version(): void
    {
        $url = '/workflow/admin/flows/'.self::PLAN.'/versions';
        $notDate = '開始日は日付で入力してください';
        $cases = [
            [[], '開始日を入力してください'], [['start_date' => null], '開始日を入力してください'],
            [['start_date' => 'x'], $notDate], [['start_date' => 20261201], $notDate],
            [['start_date' => '2026-02-30'], $notDate],
        ];

        foreach ($cases as [$data, $message]) {
            $this->assertOnlyError('post', $url, $data, 'start_date', $message);
        }
    }

    public function test_the_referrers(): void
    {
        $url = '/workflow/admin/flows/'.self::PLAN.'/referrers';
        $valid = $this->referrersInput()['referrers'][0];
        $cases = [
            [[], 'referrers'], [['referrers' => 'x'], 'referrers'], [['referrers' => ['a' => $valid]], 'referrers'],
            [['referrers' => ['x']], 'referrers.0'],
            [['referrers' => [$valid, ['type' => 'group', 'id' => 1]]], 'referrers.1'],
            [['referrers' => [['type' => 'user', 'id' => 'abc']]], 'referrers.0'],
            [['referrers' => [['id' => 1]]], 'referrers.0'],
        ];

        foreach ($cases as [$data, $key]) {
            $this->assertOnlyError('put', $url, $data, $key, Errors::SUBJECT_MISSING);
        }
    }

    /**
     * ステップの設定の欄ごとの型の誤り(選択の欄は「選択してください」の文、人数は範囲の文、必要な人数・割合は可決の条件の値の文)。
     */
    public function test_the_types_of_the_step_settings(): void
    {
        $row = $this->referrersInput()['referrers'][0];
        $prevents = FlowNodeSettingsRequest::PREVENTS_APPLICANT_APPROVAL_INVALID;
        $cases = [
            ['target_kind', ['x'], 'target_kind', Errors::TARGET_KIND_REQUIRED],
            ['target_kind', 'other', 'target_kind', Errors::TARGET_KIND_REQUIRED],
            ['targets', 'x', 'targets', Errors::TARGETS_REQUIRED],
            ['targets', [$row, 'x'], 'targets.1', Errors::SUBJECT_MISSING],
            ['relative_base', 'x', 'relative_base', Errors::RELATIVE_BASE_REQUIRED],
            ['relative_scope', 1, 'relative_scope', Errors::RELATIVE_SCOPE_REQUIRED],
            ['company_post_id', 'x', 'company_post_id', Errors::COMPANY_POST_MISSING],
            ['designator_node_code', 5, 'designator_node_code', Errors::DESIGNATOR_INVALID],
            ['min_assignees', 'x', 'min_assignees', Errors::ASSIGNEES_RANGE],
            ['max_assignees', 1.5, 'max_assignees', Errors::ASSIGNEES_RANGE],
            ['prevents_applicant_approval', 'x', 'prevents_applicant_approval', $prevents],
            ['send_back_node_codes', 'x', 'send_back_node_codes', Errors::SEND_BACK_INVALID],
            ['send_back_node_codes', [1], 'send_back_node_codes', Errors::SEND_BACK_INVALID],
            ['decision_rule', 'x', 'decision_rule', Errors::DECISION_RULE_REQUIRED],
        ];

        foreach ($cases as [$field, $value, $key, $message]) {
            $input = $this->nodeInput(3, 'council', [$field => $value]);
            $this->assertOnlyError('put', $this->councilUrl(), $input, $key, $message);
        }

        $count = $this->nodeInput(3, 'council', ['decision_value' => 'x']);
        $this->assertOnlyError('put', $this->councilUrl(), $count, 'decision_value', Errors::DECISION_COUNT_RANGE);
        $ratio = $this->nodeInput(3, 'council', ['decision_rule' => 'ratio', 'decision_value' => 'x']);
        $this->assertOnlyError('put', $this->councilUrl(), $ratio, 'decision_value', Errors::DECISION_RATIO_RANGE);
    }

    /**
     * 欠けた欄は空(null)として業務処理へ渡る: 指定の種類が欠けると業務処理の 422 target_kind、申請者本人の承認を防ぐが欠けるとオフで保存する。
     */
    public function test_a_missing_field_of_the_step_settings_is_empty(): void
    {
        $input = $this->nodeInput(3, 'council');
        unset($input['target_kind']);
        $this->assertOnlyError('put', $this->councilUrl(), $input, 'target_kind', Errors::TARGET_KIND_REQUIRED);

        $kept = $this->nodeInput(3, 'council');
        unset($kept['prevents_applicant_approval']);
        $this->sendJson('put', $this->councilUrl(), $kept)->assertRedirect();
        $this->assertFalse($this->planNode(3, 'council')->prevents_applicant_approval);
    }

    public function test_the_subject_candidates_query(): void
    {
        $type = '種類の指定が正しくありません';
        $long = str_repeat('あ', 101);

        $this->assertOnlyError('get', '/workflow/admin/subject-candidates?q=x', [], 'type', $type);
        $this->assertOnlyError('get', '/workflow/admin/subject-candidates?type=group&q=x', [], 'type', $type);
        $tooLong = '検索する語は 100 文字以内にしてください';
        $this->assertOnlyError('get', "/workflow/admin/subject-candidates?type=user&q={$long}", [], 'q', $tooLong);
    }

    private function councilUrl(): string
    {
        return '/workflow/admin/flows/'.self::PLAN.'/versions/3/nodes/council';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertOnlyError(string $method, string $url, array $data, string $key, string $message): void
    {
        $before = $this->definitionSnapshot();

        $response = $this->sendJson($method, $url, $data);

        $response->assertUnprocessable()->assertJsonValidationErrors([$key => $message]);
        $sent = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '';
        $this->assertSame([$key], array_keys((array) $response->json('errors')), $sent);
        $this->assertSame($before, $this->definitionSnapshot());
    }
}
